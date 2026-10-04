<?php
declare(strict_types=1);

/** USSD na żądanie (rozdz. 2.12, 3.10): wiersz outbox z Class = 127, odpowiedź w inbox z Class = 127. */
final class Ussd
{
    public const TIMEOUT = 60;

    /** Wysłanie kodu (także odpowiedzi w menu operatora); [id, błąd]. */
    public static function send(string $code, ?string $modem = null, ?int $parentId = null, string $purpose = 'manual'): array
    {
        $code = str_replace(' ', '', $code);
        if (!preg_match('/^[0-9*#+]{1,20}$/', $code)) {
            return [null, t('modem.err_code')];
        }
        Sync::run(true); // świeży stan żądań i import odpowiedzi czekających w inbox
        if (self::pending($modem) !== null) {
            return [null, t('modem.err_pending')];
        }
        $id = Db::tx(static function () use ($code, $modem, $parentId, $purpose): int {
            $gid = GammuDb::insertOutbox([
                'number' => $code, 'parts' => [['text' => $code, 'udh' => '']], 'coding' => SmsText::CODING_GSM, 'class' => 127,
                'report' => false, 'priority' => Outbox::PRIORITY_USSD, 'sender' => $modem,
            ]);
            return Db::insert('ussd_requests', ['modem' => $modem, 'code' => $code, 'parent_id' => $parentId, 'gammu_id' => $gid,
                'status' => 'queued', 'purpose' => $purpose, 'created_at' => now_db()]);
        });
        app_log('info', "USSD $code (#$id)");
        return [$id, null];
    }

    /** Oczekujące żądanie (queued/sent) dla modemu – albo dowolne, gdy modem nie wskazany. */
    public static function pending(?string $modem = null): ?array
    {
        $sql = "SELECT * FROM ussd_requests WHERE status IN ('queued','sent')" . ($modem !== null ? ' AND (modem = ? OR modem IS NULL)' : '')
            . ' ORDER BY id LIMIT 1';
        return Db::row($sql, $modem !== null ? [$modem] : []);
    }

    public static function get(int $id): ?array
    {
        return Db::row('SELECT * FROM ussd_requests WHERE id = ?', [$id]);
    }

    /** Stan żądań: wysłane (sentitems), błąd wysyłki, przekroczony czas oczekiwania. */
    public static function sync(): int
    {
        $n = 0;
        foreach (Db::all("SELECT * FROM ussd_requests WHERE status IN ('queued','sent')") as $r) {
            $gid = (int) $r['gammu_id'];
            if ($r['status'] === 'queued') {
                $inOutbox = Db::val('SELECT 1 FROM {g}.outbox WHERE ID = ?', [$gid]) !== null;
                if ($inOutbox) {
                    if ((int) ts($r['created_at']) < time() - 2 * self::TIMEOUT) {
                        GammuDb::cancel($gid);
                        Db::update('ussd_requests', ['status' => 'timeout'], 'id = ?', [$r['id']]);
                        $n++;
                    }
                    continue;
                }
                $sent = Db::row('SELECT Status, SendingDateTime, SenderID FROM {g}.sentitems WHERE ID = ? ORDER BY SequencePosition LIMIT 1', [$gid]);
                if ($sent === null || in_array($sent['Status'], GammuDb::SENT_ERROR, true)) {
                    Db::update('ussd_requests', ['status' => 'failed', 'response' => msg_key($sent === null ? 'ussd.removed' : 'ussd.not_sent')], 'id = ?', [$r['id']]);
                } else {
                    Db::update('ussd_requests', ['status' => 'sent', 'sent_at' => $sent['SendingDateTime'], 'modem' => $sent['SenderID'] ?: $r['modem']], 'id = ?', [$r['id']]);
                }
                $n++;
            } elseif ((int) ts($r['sent_at']) < time() - self::TIMEOUT) {
                Db::update('ussd_requests', ['status' => 'timeout'], 'id = ?', [$r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Odpowiedź z inbox (Class = 127): pierwsze oczekujące żądanie tego modemu (rozdz. 3.10). */
    public static function onResponse(array $row): void
    {
        $modem = (string) $row['RecipientID'];
        $req = Db::row("SELECT id FROM ussd_requests WHERE status IN ('sent','queued') AND (modem = ? OR modem IS NULL) ORDER BY id LIMIT 1", [$modem]);
        $data = ['status' => 'answered', 'response' => (string) $row['TextDecoded'], 'session_status' => (int) $row['Status'],
            'answered_at' => $row['ReceivingDateTime'], 'modem' => $modem ?: null];
        if ($req === null) {
            // Odpowiedź bez żądania (np. komunikat sieci) – zapisana w historii
            Db::insert('ussd_requests', $data + ['code' => '—', 'created_at' => $row['ReceivingDateTime']]);
            return;
        }
        Db::update('ussd_requests', $data, 'id = ?', [$req['id']]);
    }

    /** Zakończenie sesji menu po stronie panelu (Gammu nie ma polecenia przerwania sesji). */
    public static function close(int $id): void
    {
        Db::update('ussd_requests', ['session_status' => 2], 'id = ? AND session_status = 3', [$id]);
    }

    public static function history(int $limit = 20): array
    {
        return Db::all('SELECT r.*, p.code AS parent_code FROM ussd_requests r LEFT JOIN ussd_requests p ON p.id = r.parent_id
            ORDER BY r.id DESC LIMIT ' . $limit);
    }

    /** Kod początkowy łańcucha odpowiedzi w menu (do opisu „*100# → 1”). */
    public static function rootCode(array $r): string
    {
        return $r['parent_code'] !== null ? $r['parent_code'] . ' → ' . $r['code'] : $r['code'];
    }
}
