<?php
declare(strict_types=1);

/** Kolejka dostarczeń do kanałów: ponawianie z rosnącym odstępem, po 5 nieudanych próbach status „failed”. */
final class Deliveries
{
    public const MAX_ATTEMPTS = 5;
    private const BACKOFF = [60, 300, 900, 3600]; // s po 1., 2., 3., 4. nieudanej próbie
    private const KEEP_DAYS = 30;
    /** Łączny czas jednego przebiegu (s) – martwy serwer nie może zatrzymać synchronizacji na minuty. */
    private const BUDGET = 20;
    /** Po tylu godzinach oczekujące dostarczenie wygasa (np. kanał długo wyłączony) zamiast wysłać się masowo po włączeniu. */
    private const EXPIRE_HOURS = 48;

    /** @param array{subject:string,text:string,data?:array<string,mixed>} $msg */
    public static function enqueue(int $channelId, string $kind, array $msg): int
    {
        return Db::insert('deliveries', [
            'channel_id' => $channelId, 'kind' => $kind, 'subject' => mb_substr($msg['subject'], 0, 255),
            'body' => json_encode(['text' => $msg['text'], 'data' => $msg['data'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending', 'attempts' => 0, 'next_try_at' => now_db(), 'created_at' => now_db(),
        ]);
    }

    /**
     * Wysyłka zaległych dostarczeń; zwraca liczbę dostarczonych. Własna blokada (nie blokada synchronizacji): wolny serwer SMTP
     * lub webhook nie wstrzymuje odbioru wiadomości, a dwa procesy nie wyślą tego samego powiadomienia podwójnie.
     */
    public static function process(int $limit = 20): int
    {
        if (!Db::lock('smsgui-deliveries', 0)) {
            return 0;
        }
        try {
            return self::deliver($limit);
        } finally {
            Db::unlock('smsgui-deliveries');
        }
    }

    private static function deliver(int $limit): int
    {
        $sent = 0;
        $started = microtime(true);
        Db::exec("UPDATE deliveries SET status = 'failed', last_error = ? WHERE status = 'pending' AND created_at < ?",
            [t('channels.expired'), date('Y-m-d H:i:s', time() - self::EXPIRE_HOURS * 3600)]);
        $rows = Db::all("SELECT d.*, c.type, c.name, c.config FROM deliveries d JOIN channels c ON c.id = d.channel_id
            WHERE d.status = 'pending' AND d.next_try_at <= ? AND c.enabled = 1 ORDER BY d.id LIMIT " . (int) $limit, [now_db()]);
        foreach ($rows as $d) {
            if (microtime(true) - $started > self::BUDGET) {
                break; // reszta w następnym przebiegu
            }
            $body = json_decode((string) $d['body'], true) ?: [];
            try {
                Channels::make($d)->send(['subject' => $d['subject'], 'text' => (string) ($body['text'] ?? ''), 'data' => (array) ($body['data'] ?? [])]);
                Db::update('deliveries', ['status' => 'sent', 'attempts' => $d['attempts'] + 1, 'sent_at' => now_db(), 'last_error' => null], 'id = ?', [$d['id']]);
                Db::update('channels', ['last_ok_at' => now_db(), 'last_error' => null], 'id = ?', [$d['channel_id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $d['attempts'] + 1;
                $err = mb_substr($e->getMessage(), 0, 250);
                $final = $attempts >= self::MAX_ATTEMPTS;
                Db::update('deliveries', [
                    'status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => $err,
                    'next_try_at' => date('Y-m-d H:i:s', time() + self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]),
                ], 'id = ?', [$d['id']]);
                Db::update('channels', ['last_error' => $err], 'id = ?', [$d['channel_id']]);
                app_log('warning', "dostarczenie #{$d['id']} ({$d['type']}, próba $attempts): $err");
            }
        }
        self::cleanup();
        return $sent;
    }

    public static function counts(): array
    {
        return array_column(Db::all('SELECT status, COUNT(*) AS n FROM deliveries GROUP BY status'), 'n', 'status') + ['pending' => 0, 'sent' => 0, 'failed' => 0];
    }

    /** Raz na dobę usuwa zakończone dostarczenia starsze niż 30 dni. */
    private static function cleanup(): void
    {
        if (Settings::get('deliveries_cleanup') === date('Y-m-d')) {
            return;
        }
        Db::exec("DELETE FROM deliveries WHERE status <> 'pending' AND created_at < ?", [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        Settings::set('deliveries_cleanup', date('Y-m-d'));
    }
}
