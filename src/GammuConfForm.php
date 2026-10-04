<?php
declare(strict_types=1);

/** Pola formularza „Ustawienia” konfiguracji Gammu (rozdz. 2.10a) – bez parametrów zarządzanych przez panel. */
final class GammuConfForm
{
    private const BOOL = ['' => 'domyślnie', 'yes' => 'tak', 'no' => 'nie'];

    /** Karty formularza: tytuł → lista pól [sekcja, klucz, rodzaj, opis, opcje]. */
    public static function cards(): array
    {
        return [
            'Modem – sekcja [gammu]' => [
                ['gammu', 'device', 'device', 'Port modemu – najlepiej stała nazwa z ' . cfg('serial_dir')],
                ['gammu', 'connection', 'select', 'Sposób połączenia z modemem', ['at' => 'at (zalecane)', 'at115200' => 'at115200', 'at57600' => 'at57600',
                    'at38400' => 'at38400', 'at19200' => 'at19200', 'at9600' => 'at9600']],
            ],
            'Bramka – sekcja [smsd]' => [
                ['smsd', 'phoneid', 'text', 'Nazwa modemu widoczna w panelu'],
                ['smsd', 'pin', 'password', 'PIN karty SIM · puste = bez PIN'],
                ['smsd', 'smsc', 'text', 'Numer centrum SMS – zwykle niepotrzebny'],
                ['smsd', 'send', 'select', 'Wysyłanie · domyślnie: tak', self::BOOL],
                ['smsd', 'receive', 'select', 'Odbieranie · domyślnie: tak', self::BOOL],
            ],
            'Wysyłka i raporty doręczenia' => [
                ['smsd', 'deliveryreportdelay', 'number', 'Jak długo czekać na raport doręczenia (s) · zalecane 172800 (2 dni), domyślnie 600'],
                ['smsd', 'maxretries', 'number', 'Ponowienia nieudanej wysyłki · domyślnie 1'],
                ['smsd', 'retrytimeout', 'number', 'Odstęp między ponowieniami (s) · domyślnie 600'],
                ['smsd', 'multiparttimeout', 'number', 'Czekanie na brakujące części odebranej wiadomości (s) · domyślnie 600'],
            ],
            'Stan modemu i niezawodność' => [
                ['smsd', 'statusfrequency', 'number', 'Co ile sekund odświeżać stan · domyślnie 60'],
                ['smsd', 'checksignal', 'select', 'Sprawdzanie sygnału · domyślnie: tak', self::BOOL],
                ['smsd', 'checknetwork', 'select', 'Sprawdzanie sieci · domyślnie: tak', self::BOOL],
                ['smsd', 'checkbattery', 'select', 'Sprawdzanie baterii · domyślnie: tak', self::BOOL],
                ['smsd', 'resetfrequency', 'number', 'Okresowy reset modemu (s) · 0 = wyłączony'],
                ['smsd', 'hardresetfrequency', 'number', 'Okresowy twardy reset (s) · 0 = wyłączony'],
                ['smsd', 'loopsleep', 'number', 'Czas pętli (s) · domyślnie 1'],
                ['smsd', 'commtimeout', 'number', 'Czekanie na modem (s) · domyślnie 30'],
                ['smsd', 'sendtimeout', 'number', 'Czekanie na wysłanie (s) · domyślnie 30'],
            ],
            'Log' => [
                ['smsd', 'debuglevel', 'select', 'Szczegółowość logu Gammu', ['' => 'domyślny (0)', '0' => '0 – tylko błędy', '1' => '1 – podstawowy',
                    '2' => '2 – szczegółowy', '3' => '3 – bardzo szczegółowy', '255' => '255 – wszystko']],
                ['smsd', 'logfile', 'text', 'Panel musi mieć prawo odczytu tego pliku'],
            ],
        ];
    }

    /** Porty modemu wykryte w serial_dir (/dev/serial/by-id). */
    public static function ports(): array
    {
        $dir = (string) cfg('serial_dir');
        return array_values(array_filter(glob(rtrim($dir, '/') . '/*') ?: [], static fn ($p) => !is_dir($p) && !str_ends_with($p, '.php')));
    }

    /**
     * Nowa treść pliku z formularza; [konfiguracja, lista zmian „klucz: a → b”, błędy pól].
     */
    public static function apply(GammuConf $conf, array $post): array
    {
        $copy = GammuConf::parse($conf->text());
        $changes = [];
        $errors = [];
        foreach (self::cards() as $fields) {
            foreach ($fields as $f) {
                [$section, $key, $type] = $f;
                $name = $section . '_' . $key;
                $value = trim((string) ($post[$name] ?? ''));
                $old = $conf->get($section, $key);
                if ($type === 'password' && $value === GammuConf::MASK) {
                    continue; // niezmieniony PIN
                }
                if ($type === 'number' && $value !== '' && !ctype_digit($value)) {
                    $errors[$name] = 'Podaj liczbę całkowitą.';
                    continue;
                }
                // Wartość spoza listy jest dozwolona, jeśli to niezmieniona wartość z pliku (np. debuglevel = 4)
                if ($type === 'select' && !array_key_exists($value, $f[4]) && $value !== (string) $old) {
                    $errors[$name] = 'Nieprawidłowa wartość.';
                    continue;
                }
                if (preg_match('/[\r\n]/', $value)) {
                    $errors[$name] = 'Wartość nie może zawierać nowej linii.';
                    continue;
                }
                if ((string) $old === $value) {
                    continue;
                }
                $copy->set($section, $key, $value === '' ? null : $value);
                $show = static fn (?string $v) => $type === 'password' && $v !== null && $v !== '' ? '****' : ($v === null || $v === '' ? '(domyślnie)' : $v);
                $changes[] = $key . ' ' . $show($old) . ' → ' . $show($value === '' ? null : $value);
            }
        }
        return [$copy, $changes, $errors];
    }
}
