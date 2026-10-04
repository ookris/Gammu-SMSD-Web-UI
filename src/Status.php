<?php
declare(strict_types=1);

/** Stan bramki dla wskaźnika w menu, pulpitu i kontroli zdrowia: usługa, modem, synchronizacja, liczniki. */
final class Status
{
    private static array $memo = [];

    /** Stan usługi Gammu: active / inactive / failed / unknown (zapisywany przez synchronizację, co najwyżej co 15 s). */
    public static function service(): string
    {
        $checked = ts(Settings::get('service_checked_at'));
        if ($checked === null || time() - $checked > 60) {
            return Service::refreshStatus();
        }
        return (string) Settings::get('service_status', 'unknown');
    }

    /** Stan modemów z kopii tabeli phones (modem_status). */
    public static function modems(): array
    {
        return self::$memo['modems'] ??= Db::all('SELECT * FROM modem_status ORDER BY modem');
    }

    public static function modem(): ?array
    {
        return self::modems()[0] ?? null;
    }

    /** Modem niedostępny, gdy phones.UpdatedInDB starszy niż StatusFrequency + 60 s (rozdz. 3.9). */
    public static function modemAvailable(?array $m): bool
    {
        if ($m === null || ($m['present'] ?? '1') === '0') {
            return false;
        }
        $t = ts($m['gammu_updated_at']);
        return $t !== null && time() - $t <= GammuConf::statusFrequency() + 60;
    }

    /** Wskaźnik w menu: poziom ok/warn/err i trzy linie tekstu. */
    public static function indicator(): array
    {
        $service = self::service();
        $m = self::modem();
        $sync = 'Synchronizacja ' . fmt_ago(Settings::get('last_sync_at'));
        if ($service !== 'active') {
            return ['level' => 'err', 'title' => 'Gammu: nie działa', 'line2' => 'Usługa: ' . $service, 'line3' => $sync,
                'modem' => $m['modem'] ?? ''];
        }
        if (!self::modemAvailable($m)) {
            $since = $m && $m['gammu_updated_at'] ? ' od ' . date('H:i', (int) ts($m['gammu_updated_at'])) : '';
            return ['level' => 'warn', 'title' => 'Gammu: działa', 'line2' => 'Modem niedostępny' . $since, 'line3' => $sync,
                'modem' => $m['modem'] ?? ''];
        }
        $signal = (int) $m['signal_pct'] >= 0 ? ' · sygnał ' . $m['signal_pct'] . ' %' : '';
        return ['level' => 'ok', 'title' => 'Gammu: działa', 'line2' => 'Modem ' . $m['modem'] . $signal, 'line3' => $sync,
            'modem' => $m['modem']];
    }

    public static function unreadMessages(): int
    {
        return self::$memo['unread'] ??= (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'in' AND is_read = 0");
    }

    public static function unreadCalls(): int
    {
        return self::$memo['calls'] ??= (int) Db::val('SELECT COUNT(*) FROM calls WHERE is_read = 0');
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
