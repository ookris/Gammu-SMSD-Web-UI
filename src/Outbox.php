<?php
declare(strict_types=1);

/** Tworzenie wiadomości wychodzących (pojedyncze, masowe, planowane), ponawianie i anulowanie (rozdz. 3.3, 3.6). */
final class Outbox
{
    public const PRIORITY_USSD = 20;
    public const PRIORITY_SINGLE = 10;
    public const PRIORITY_BULK = 0;

    /**
     * Zapis wiadomości do messages + outbox/outbox_multipart w jednej transakcji. Zwraca messages.id.
     * @param array{phone:string,body:string,translit?:bool,flash?:bool,report?:bool,send_at?:?string,priority?:int,
     *   window?:?array{0:string,1:string},batch_id?:?string,source?:string,modem?:?string} $o
     */
    public static function create(array $o): int
    {
        $text = SmsText::normalize($o['body']);
        if (!empty($o['translit'])) {
            $text = SmsText::translit($text);
        }
        $a = SmsText::analyze($text);
        if ($a['chars'] === 0) {
            throw new InvalidArgumentException(t('compose.err_text'));
        }
        if ($a['too_long']) {
            throw new InvalidArgumentException(t('sms.too_long', ['parts' => $a['parts'], 'max' => SmsText::MAX_PARTS]));
        }
        $sendAt = $o['send_at'] ?? null;
        $window = $o['window'] ?? null;
        $now = now_db();
        $scheduled = ($sendAt !== null && $sendAt > $now) || ($window !== null && !self::inWindow($window, date('H:i:s')));

        return Db::tx(static function () use ($o, $text, $a, $sendAt, $window, $now, $scheduled): int {
            $ref = $a['parts'] > 1 ? Settings::nextUdhRef() : null;
            $parts = SmsText::split($text, $ref ?? 0);
            $id = Db::insert('messages', [
                'direction' => 'out',
                'phone' => $o['phone'],
                'body' => $text,
                'encoding' => $a['gsm'] ? 'GSM' : 'UCS2',
                'parts' => count($parts),
                'status' => $scheduled ? 'scheduled' : 'queued',
                'flash' => (int) !empty($o['flash']),
                'report' => (int) !empty($o['report']),
                'priority' => $o['priority'] ?? self::PRIORITY_SINGLE,
                'send_window' => (int) ($window !== null),
                'source' => $o['source'] ?? 'gui',
                'batch_id' => $o['batch_id'] ?? null,
                'udh_ref' => $ref,
                'modem_requested' => $o['modem'] ?? null,
                'is_read' => 1,
                'scheduled_at' => $sendAt ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $gid = GammuDb::insertOutbox([
                'number' => Phone::toGammu($o['phone']),
                'parts' => $parts,
                'coding' => $a['coding'],
                'class' => !empty($o['flash']) ? 0 : -1,
                'report' => !empty($o['report']),
                'send_at' => $sendAt,
                'after' => $window[0] ?? null,
                'before' => $window[1] ?? null,
                'priority' => $o['priority'] ?? self::PRIORITY_SINGLE,
                'sender' => $o['modem'] ?? null,
            ]);
            Db::update('messages', ['gammu_id' => $gid], 'id = ?', [$id]);
            return $id;
        });
    }

    /** Okno wysyłki „08:00:00”–„21:00:00” (bez przejścia przez północ – ograniczenie Gammu). */
    public static function inWindow(array $window, string $time): bool
    {
        return $time >= $window[0] && $time <= $window[1];
    }

    /** Anulowanie: cancelled | busy (Gammu właśnie wysyła) | invalid. */
    public static function cancel(int $id): string
    {
        $m = Db::row("SELECT id, gammu_id, status, retries FROM messages WHERE id = ? AND direction = 'out'", [$id]);
        if ($m === null || !in_array($m['status'], ['scheduled', 'queued'], true) || $m['gammu_id'] === null) {
            return 'invalid';
        }
        $partly = (int) $m['retries'] > 0 && GammuDb::partlySent((int) $m['gammu_id']);
        if (!GammuDb::cancel((int) $m['gammu_id'])) {
            return Db::val('SELECT 1 FROM {g}.outbox WHERE ID = ?', [$m['gammu_id']]) !== null ? 'busy' : 'invalid';
        }
        Db::update('messages', [
            'status' => 'cancelled',
            'error' => $partly ? msg_key('gammu.partly_sent') : null,
            'updated_at' => now_db(),
        ], 'id = ?', [$id]);
        return 'cancelled';
    }

    /** Ponowienie wiadomości z błędem / niedoręczonej: nowy wiersz outbox, nowy gammu_id (rozdz. 3.3). */
    public static function retry(int $id): bool
    {
        $m = Db::row("SELECT * FROM messages WHERE id = ? AND direction = 'out'", [$id]);
        if ($m === null || !in_array($m['status'], ['failed', 'undelivered', 'cancelled'], true) || Phone::isAlpha($m['phone'])) {
            return false;
        }
        Db::tx(static function () use ($m): void {
            $a = SmsText::analyze($m['body']);
            $ref = $a['parts'] > 1 ? Settings::nextUdhRef() : null;
            $gid = GammuDb::insertOutbox([
                'number' => Phone::toGammu($m['phone']),
                'parts' => SmsText::split($m['body'], $ref ?? 0),
                'coding' => $a['coding'],
                'class' => $m['flash'] ? 0 : -1,
                'report' => (bool) $m['report'],
                'priority' => (int) $m['priority'],
                'sender' => $m['modem_requested'],
            ]);
            Db::update('messages', [
                'gammu_id' => $gid, 'udh_ref' => $ref, 'status' => 'queued', 'error' => null, 'status_code' => null,
                'retries' => 0, 'sent_at' => null, 'delivered_at' => null, 'scheduled_at' => now_db(), 'updated_at' => now_db(),
            ], 'id = ?', [$m['id']]);
        });
        return true;
    }

    /** Wysyłka testowa z konsoli (instalator): `smsgui send <numer> <treść> --wait=N`. */
    public static function cliSend(string $number, string $text, int $wait): int
    {
        $phone = Phone::normalize($number);
        if ($phone === null) {
            fwrite(STDERR, 'Nieprawidłowy numer: ' . Phone::error($number) . PHP_EOL);
            return 1;
        }
        $id = self::create(['phone' => $phone, 'body' => $text, 'report' => true, 'priority' => self::PRIORITY_SINGLE]);
        echo 'Wiadomość #' . $id . ' w kolejce do ' . Phone::format($phone) . PHP_EOL;
        $deadline = time() + $wait;
        $status = 'queued';
        while (time() < $deadline) {
            sleep(3);
            Sync::run(true);
            $status = (string) Db::val('SELECT status FROM messages WHERE id = ?', [$id]);
            if (!in_array($status, ['queued', 'scheduled'], true)) {
                break;
            }
        }
        $m = Db::row('SELECT status, error FROM messages WHERE id = ?', [$id]);
        echo 'Status: ' . t('status.' . $m['status']) . ($m['error'] ? ' (' . tr($m['error']) . ')' : '') . PHP_EOL;
        return in_array($m['status'], ['failed', 'undelivered'], true) ? 1 : 0;
    }
}
