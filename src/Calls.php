<?php
declare(strict_types=1);

/** Połączenia przychodzące (rozdz. 2.13, 3.11): zapis z hooka RunOnIncomingCall, lista, liczniki. */
final class Calls
{
    /** raw_number dla numeru, którego nie da się zapisać (wcześniejsze wiersze: „nieprawidłowy numer”). */
    public const INVALID = '@calls.invalid_number';

    /** Numer z sieci GSM: cyfry, +, *, #; pusty = numer zastrzeżony. */
    public static function validNumber(string $n): bool
    {
        return $n === '' || (bool) preg_match('/^\+?[0-9*#]{1,20}$/', $n);
    }

    /**
     * Hook wywoływany przez Gammu: `smsgui hook call --phone=<PhoneID> <numer>`. Działa jako użytkownik demona,
     * łączy się kontem smsgui_hook (tylko INSERT do calls) i nie robi nic więcej – automatyzacje wykonuje proces w tle.
     */
    public static function hook(string $phoneId, string $number): int
    {
        $number = trim($number);
        $valid = self::validNumber($number);
        $phone = $valid && $number !== '' ? (Phone::fromGammu($number) ?: '') : '';
        $raw = $valid ? $number : self::INVALID;
        $modem = preg_match('/^[A-Za-z0-9_.-]{0,64}$/', $phoneId) ? $phoneId : '';
        try {
            $pdo = self::hookConnection();
            $pdo->prepare('INSERT INTO calls (phone, raw_number, modem, received_at) VALUES (?, ?, ?, ?)')
                ->execute([mb_substr($phone, 0, 32), mb_substr($raw, 0, 40), $modem, now_db()]);
        } catch (Throwable $e) {
            fwrite(STDERR, 'smsgui hook: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
        return 0;
    }

    /** Połączenie kontem z /etc/smsgui/hook.cnf (sekcja [client]: user, password, host/socket, database). */
    private static function hookConnection(): PDO
    {
        $cnf = (string) cfg('hook.cnf');
        $ini = is_readable($cnf) ? parse_ini_file($cnf, true, INI_SCANNER_RAW) : false;
        if (!is_array($ini) || !isset($ini['client'])) {
            return Db::connect(); // brak pliku – konto panelu (środowisko deweloperskie)
        }
        $c = $ini['client'];
        $db = $c['database'] ?? 'smsgui';
        $dsn = isset($c['socket']) ? "mysql:unix_socket={$c['socket']};dbname=$db;charset=utf8mb4"
            : 'mysql:host=' . ($c['host'] ?? 'localhost') . (isset($c['port']) ? ';port=' . (int) $c['port'] : '') . ';dbname=' . $db . ';charset=utf8mb4';
        return Db::connect($dsn, (string) ($c['user'] ?? 'smsgui_hook'), (string) ($c['password'] ?? ''));
    }

    /** Krok synchronizacji: nowe połączenia → oznaczone jako przetworzone (automatyzacje – w rozszerzeniach). */
    public static function process(): int
    {
        return Db::exec('UPDATE calls SET processed = 1 WHERE processed = 0');
    }

    /** Włączenie/wyłączenie w gammu-smsdrc: HangupCalls + RunOnIncomingCall (zmiana przez okno potwierdzenia). */
    public static function confChange(bool $enable): GammuConf
    {
        $conf = GammuConf::load(true) ?? throw new RuntimeException(t('file.cannot_read', ['path' => GammuConf::path()]));
        $phone = $conf->get('smsd', 'phoneid') ?: 'GSM1';
        $copy = GammuConf::parse($conf->text());
        $copy->set('smsd', 'hangupcalls', $enable ? 'yes' : null);
        // Gammu uruchamia polecenie przez sh -c – PhoneID z edytowalnej konfiguracji musi być zacytowany
        $copy->set('smsd', 'runonincomingcall', $enable ? cfg('hook.command') . ' --phone=' . escapeshellarg($phone) : null);
        return $copy;
    }

    public static function enabledInConf(): bool
    {
        $c = GammuConf::load();
        return $c !== null && strtolower((string) $c->get('smsd', 'hangupcalls')) === 'yes' && $c->get('smsd', 'runonincomingcall') !== null;
    }
}
