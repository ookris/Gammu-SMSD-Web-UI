<?php
declare(strict_types=1);

/** Ustawienia „biznesowe” w tabeli settings – edytowalne z WWW (rozdz. 2.15) – oraz stan pracy panelu. */
final class Settings
{
    public const DEFAULTS = [
        'report_default' => '1',
        'translit_default' => '0',
        'throttle_per_min' => '10',
        'window_enabled' => '1',
        'window_from' => '08:00',
        'window_to' => '21:00',
        'ussd_codes' => '[{"name":"Saldo","code":"*101#"},{"name":"Numer karty","code":"*100#"}]',
        'calls_enabled' => '0',
        'session_hours' => '8',
        'backup_keep' => '30',
    ];

    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$cache ??= array_column(Db::all('SELECT `key`, `value` FROM settings'), 'value', 'key');
        return self::$cache[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function json(string $key): array
    {
        $v = json_decode((string) self::get($key), true);
        return is_array($v) ? $v : [];
    }

    public static function set(string $key, string|int|bool|array|null $value): void
    {
        $value = match (true) {
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
        Db::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /** Wyczyszczenie pamięci (proces w tle czyta świeże wartości w każdym przebiegu). */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Kolejny 8-bitowy numer referencyjny UDH (00–FF), atomowo w bazie. */
    public static function nextUdhRef(): int
    {
        Db::exec("INSERT INTO settings (`key`, `value`) VALUES ('udh_ref', '1')
                  ON DUPLICATE KEY UPDATE `value` = MOD(CAST(`value` AS UNSIGNED) + 1, 256)");
        $ref = (int) Db::val("SELECT `value` FROM settings WHERE `key` = 'udh_ref'");
        if (self::$cache !== null) {
            self::$cache['udh_ref'] = (string) $ref;
        }
        return $ref;
    }
}
