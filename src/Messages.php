<?php
declare(strict_types=1);

/** Operacje na historii wiadomości: usuwanie, czyszczenie starszych niż N dni. */
final class Messages
{
    public static function delete(array $ids, ?string $direction = null, ?string $phone = null): int
    {
        if ($ids === []) {
            return 0;
        }
        $params = $ids;
        $sql = 'DELETE FROM messages WHERE id IN (' . Db::in($ids) . ')';
        foreach (['direction' => $direction, 'phone' => $phone] as $col => $val) {
            if ($val !== null) {
                $sql .= " AND $col = ?";
                $params[] = $val;
            }
        }
        // Nie usuwamy wiadomości, które Gammu jeszcze może wysłać – najpierw trzeba je anulować
        $sql .= " AND NOT (direction = 'out' AND status IN ('scheduled','queued'))";
        return Db::exec($sql, $params);
    }

    /** Usunięcie wiadomości, połączeń i historii USSD starszych niż N dni oraz zaimportowanych wierszy Gammu. */
    public static function cleanup(int $days): string
    {
        $before = now_db(-$days * 86400);
        $m = Db::exec("DELETE FROM messages WHERE created_at < ? AND NOT (direction = 'out' AND status IN ('scheduled','queued'))", [$before]);
        $c = Db::exec('DELETE FROM calls WHERE received_at < ?', [$before]);
        $u = Db::exec("DELETE FROM ussd_requests WHERE created_at < ? AND status NOT IN ('queued','sent')", [$before]);
        $i = Db::exec("DELETE FROM {g}.inbox WHERE Processed = 'true' AND ReceivingDateTime < ?", [$before]);
        $s = Db::exec('DELETE FROM {g}.sentitems WHERE InsertIntoDB < ?', [$before]);
        app_log('info', "czyszczenie historii > $days dni: wiadomości $m, połączenia $c, USSD $u, inbox $i, sentitems $s");
        return "Usunięto: wiadomości $m, połączenia $c, USSD $u, wiersze Gammu inbox $i i sentitems $s.";
    }

    /** Automatyczne czyszczenie raz na dobę, jeśli włączone w ustawieniach (cleanup_days > 0). */
    public static function dailyCleanup(): void
    {
        $days = Settings::int('cleanup_days');
        if ($days < 1 || Settings::get('cleanup_last') === date('Y-m-d')) {
            return;
        }
        self::cleanup($days);
        Settings::set('cleanup_last', date('Y-m-d')); // po błędzie kolejny przebieg spróbuje ponownie
    }
}
