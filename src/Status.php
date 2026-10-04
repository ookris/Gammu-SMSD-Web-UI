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

    /** Modem niedostępny, gdy phones.UpdatedInDB starszy niż StatusFrequency + 60 s (Gammu odświeża wiersz co StatusFrequency). */
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
        $sync = t('status.sync', ['ago' => fmt_ago(Settings::get('last_sync_at'))]);
        if ($service !== 'active') {
            return ['level' => 'err', 'title' => t('status.gammu_down'), 'line2' => t('status.service', ['state' => $service]), 'line3' => $sync,
                'modem' => $m['modem'] ?? ''];
        }
        if (!self::modemAvailable($m)) {
            $line2 = $m && $m['gammu_updated_at'] ? t('status.modem_down_since', ['time' => date('H:i', (int) ts($m['gammu_updated_at']))])
                : t('status.modem_down');
            return ['level' => 'warn', 'title' => t('status.gammu_up'), 'line2' => $line2, 'line3' => $sync,
                'modem' => $m['modem'] ?? ''];
        }
        $line2 = (int) $m['signal_pct'] >= 0 ? t('status.modem_signal', ['modem' => $m['modem'], 'pct' => $m['signal_pct']])
            : t('status.modem', ['modem' => $m['modem']]);
        return ['level' => 'ok', 'title' => t('status.gammu_up'), 'line2' => $line2, 'line3' => $sync,
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
