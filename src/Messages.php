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
        $where = 'id IN (' . Db::in($ids) . ')';
        foreach (['direction' => $direction, 'phone' => $phone] as $col => $val) {
            if ($val !== null) {
                $where .= " AND $col = ?";
                $params[] = $val;
            }
        }
        // Nie usuwamy wiadomości, które Gammu jeszcze może wysłać – najpierw trzeba je anulować
        $where .= " AND NOT (direction = 'out' AND status IN ('scheduled','queued'))";
        return Db::tx(static function () use ($where, $params): int {
            self::tombstone($where, $params);
            return Db::exec("DELETE FROM messages WHERE $where", $params);
        });
    }

    /** Zapamiętuje usuwane wiadomości zewnętrzne, żeby synchronizacja nie przywróciła ich z sentitems. */
    public static function tombstone(string $where, array $params): void
    {
        Db::exec("INSERT IGNORE INTO deleted_external (gammu_id, deleted_at)
            SELECT gammu_id, NOW() FROM messages WHERE source = 'external' AND gammu_id IS NOT NULL AND ($where)", $params);
    }

    /** Usunięcie wiadomości, połączeń i historii USSD starszych niż N dni oraz zaimportowanych wierszy Gammu. */
    public static function cleanup(int $days): string
    {
        $before = now_db(-$days * 86400);
        $keep = "created_at < ? AND NOT (direction = 'out' AND status IN ('scheduled','queued'))";
        if ($days < 7) { // Sync::external() patrzy 7 dni wstecz – przy dłuższej retencji sentitems znika razem z wiadomością
            self::tombstone($keep, [$before]);
        }
        $m = Db::exec("DELETE FROM messages WHERE $keep", [$before]);
        Db::exec('DELETE FROM deleted_external WHERE deleted_at < ?', [now_db(-30 * 86400)]);
        $c = Db::exec('DELETE FROM calls WHERE received_at < ?', [$before]);
        $u = Db::exec("DELETE FROM ussd_requests WHERE created_at < ? AND status NOT IN ('queued','sent')", [$before]);
        // Opisy wysyłek (settings.batch_*) bez żadnej wiadomości
        Db::exec("DELETE FROM settings WHERE `key` LIKE 'batch\\_%' AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.batch_id = SUBSTRING(settings.`key`, 7))");
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
