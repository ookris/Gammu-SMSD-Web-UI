<?php
declare(strict_types=1);

/** Statystyki wiadomości i połączeń z tabel panelu. */
final class Stats
{
    /** Wiadomości wysłane (SMS, nie części) wg dni: ostatnie $days dni, od najstarszego. @return list<array{day:string,out:int,in:int}> */
    public static function daily(int $days = 30): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $out = array_column(Db::all("SELECT DATE(COALESCE(sent_at, created_at)) AS d, COUNT(*) AS n FROM messages
            WHERE direction = 'out' AND status IN ('sent','delivered','undelivered') AND COALESCE(sent_at, created_at) >= ? GROUP BY d", [$from . ' 00:00:00']), 'n', 'd');
        $in = array_column(Db::all("SELECT DATE(COALESCE(received_at, created_at)) AS d, COUNT(*) AS n FROM messages
            WHERE direction = 'in' AND COALESCE(received_at, created_at) >= ? GROUP BY d", [$from . ' 00:00:00']), 'n', 'd');
        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $rows[] = ['day' => $d, 'out' => (int) ($out[$d] ?? 0), 'in' => (int) ($in[$d] ?? 0)];
        }
        return $rows;
    }

    /** Ostatnie $months miesięcy (z bieżącym), od najstarszego. @return list<array{month:string,out:int,in:int}> */
    public static function monthly(int $months = 12): array
    {
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $out = array_column(Db::all("SELECT DATE_FORMAT(COALESCE(sent_at, created_at), '%Y-%m') AS m, COUNT(*) AS n FROM messages
            WHERE direction = 'out' AND status IN ('sent','delivered','undelivered') AND COALESCE(sent_at, created_at) >= ? GROUP BY m", [$from . ' 00:00:00']), 'n', 'm');
        $in = array_column(Db::all("SELECT DATE_FORMAT(COALESCE(received_at, created_at), '%Y-%m') AS m, COUNT(*) AS n FROM messages
            WHERE direction = 'in' AND COALESCE(received_at, created_at) >= ? GROUP BY m", [$from . ' 00:00:00']), 'n', 'm');
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
            $rows[] = ['month' => $m, 'out' => (int) ($out[$m] ?? 0), 'in' => (int) ($in[$m] ?? 0)];
        }
        return $rows;
    }

    /** Doręczenia z ostatnich $days dni wśród wiadomości z raportem, które dostały rozstrzygnięcie. @return array{delivered:int,undelivered:int,pct:?float} */
    public static function delivery(int $days = 30): array
    {
        $r = Db::row("SELECT SUM(status = 'delivered') AS d, SUM(status = 'undelivered') AS u FROM messages
            WHERE direction = 'out' AND report = 1 AND status IN ('delivered','undelivered') AND updated_at >= ?", [date('Y-m-d H:i:s', time() - $days * 86400)]);
        $d = (int) ($r['d'] ?? 0);
        $u = (int) ($r['u'] ?? 0);
        return ['delivered' => $d, 'undelivered' => $u, 'pct' => $d + $u > 0 ? round(100 * $d / ($d + $u), 1) : null];
    }

    /** Najaktywniejsze numery (odebrane + wysłane) w ostatnich $days dniach. @return list<array{phone:string,n:int,in:int,out:int}> */
    public static function top(int $limit = 5, int $days = 30): array
    {
        return array_map(static fn ($r) => ['phone' => $r['phone'], 'n' => (int) $r['n'], 'in' => (int) $r['i'], 'out' => (int) $r['o']],
            Db::all("SELECT phone, COUNT(*) AS n, SUM(direction = 'in') AS i, SUM(direction = 'out') AS o FROM messages
                WHERE COALESCE(received_at, created_at) >= ? AND status NOT IN ('cancelled') GROUP BY phone ORDER BY n DESC, phone LIMIT " . (int) $limit,
                [date('Y-m-d H:i:s', time() - $days * 86400)]));
    }

    public static function calls(int $days = 30): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM calls WHERE received_at >= ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    /** Części SMS (to, co liczy operator) w bieżącym miesiącu: wysłane i czekające w kolejce, bez błędów i anulowanych. */
    public static function monthParts(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(parts), 0) FROM messages WHERE direction = 'out'
            AND status IN ('queued','scheduled','sent','delivered','undelivered') AND COALESCE(sent_at, scheduled_at, created_at) >= ?", [date('Y-m-01 00:00:00')]);
    }

    /** Miesięczny limit z ustawień: ['limit' => int, 'used' => int, 'pct' => int] albo null, gdy wyłączony. */
    public static function limit(): ?array
    {
        $limit = Settings::int('monthly_limit');
        if ($limit < 1) {
            return null;
        }
        $used = self::monthParts();
        return ['limit' => $limit, 'used' => $used, 'pct' => (int) floor(100 * $used / $limit)];
    }
}
