<?php
declare(strict_types=1);

/** Raport wysyłki do wielu odbiorców (batch_id) – rozdz. 2.3. */
final class Batch
{
    public static function valid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', $id);
    }

    /** Opis wysyłki zapisany przy wysyłce (treść, etykieta, tempo). */
    public static function meta(string $id): array
    {
        $meta = Settings::json('batch_' . $id);
        $row = Db::row('SELECT MIN(created_at) AS created, MAX(scheduled_at) AS last_at, MAX(parts) AS parts, MAX(report) AS report,
            MIN(body) AS body, COUNT(*) AS n FROM messages WHERE batch_id = ?', [$id]);
        return $meta + ['text' => $row['body'] ?? '', 'label' => '', 'per_min' => 0, 'report' => (bool) ($row['report'] ?? 0)]
            + ['created' => $row['created'] ?? null, 'last_at' => $row['last_at'] ?? null, 'parts' => (int) ($row['parts'] ?? 1), 'n' => (int) ($row['n'] ?? 0)];
    }

    /** Liczniki: scheduled, queued, sent, delivered, failed (z undelivered), cancelled, done (zakończone), total. */
    public static function stats(string $id): array
    {
        $s = ['scheduled' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0, 'total' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM messages WHERE batch_id = ? GROUP BY status', [$id]) as $r) {
            $key = $r['status'] === 'undelivered' ? 'failed' : $r['status'];
            $s[$key] = ($s[$key] ?? 0) + (int) $r['n'];
            $s['total'] += (int) $r['n'];
        }
        $s['done'] = $s['sent'] + $s['delivered'] + $s['failed'] + $s['cancelled'];
        return $s;
    }

    public static function last(): ?string
    {
        $id = Db::val("SELECT batch_id FROM messages WHERE batch_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        return $id === null ? null : (string) $id;
    }

    public static function recipients(string $id): array
    {
        return Db::all('SELECT * FROM messages WHERE batch_id = ? ORDER BY id', [$id]);
    }

    public static function retryFailed(string $id): int
    {
        $n = 0;
        foreach (Db::col("SELECT id FROM messages WHERE batch_id = ? AND status IN ('failed','undelivered')", [$id]) as $mid) {
            $n += (int) Outbox::retry((int) $mid);
        }
        return $n;
    }

    /** Anuluj pozostałe: [anulowane, w trakcie wysyłki]. */
    public static function cancelRemaining(string $id): array
    {
        $ok = $busy = 0;
        foreach (Db::col("SELECT id FROM messages WHERE batch_id = ? AND status IN ('scheduled','queued')", [$id]) as $mid) {
            $r = Outbox::cancel((int) $mid);
            $ok += (int) ($r === 'cancelled');
            $busy += (int) ($r === 'busy');
        }
        return [$ok, $busy];
    }
}
