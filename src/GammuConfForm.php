<?php
declare(strict_types=1);

/** Pola formularza „Ustawienia” konfiguracji Gammu – bez parametrów zarządzanych przez panel. */
final class GammuConfForm
{
    /** Karty formularza: tytuł → lista pól [sekcja, klucz, rodzaj, opis, opcje]. */
    public static function cards(): array
    {
        $bool = ['' => t('config.default'), 'yes' => t('common.yes'), 'no' => t('common.no')];
        return [
            t('form.card_modem') => [
                ['gammu', 'device', 'device', t('form.device', ['dir' => cfg('serial_dir')])],
                ['gammu', 'connection', 'select', t('form.connection'), ['at' => t('form.recommended'), 'at115200' => 'at115200', 'at57600' => 'at57600',
                    'at38400' => 'at38400', 'at19200' => 'at19200', 'at9600' => 'at9600']],
            ],
            t('form.card_gateway') => [
                ['smsd', 'phoneid', 'text', t('form.phoneid')],
                ['smsd', 'pin', 'password', t('form.pin')],
                ['smsd', 'smsc', 'text', t('form.smsc')],
                ['smsd', 'send', 'select', t('form.send'), $bool],
                ['smsd', 'receive', 'select', t('form.receive'), $bool],
            ],
            t('form.card_sending') => [
                ['smsd', 'deliveryreportdelay', 'number', t('form.drd')],
                ['smsd', 'maxretries', 'number', t('form.maxretries')],
                ['smsd', 'retrytimeout', 'number', t('form.retrytimeout')],
                ['smsd', 'multiparttimeout', 'number', t('form.multiparttimeout')],
            ],
            t('form.card_reliability') => [
                ['smsd', 'statusfrequency', 'number', t('form.statusfrequency')],
                ['smsd', 'checksignal', 'select', t('form.checksignal'), $bool],
                ['smsd', 'checknetwork', 'select', t('form.checknetwork'), $bool],
                ['smsd', 'checkbattery', 'select', t('form.checkbattery'), $bool],
                ['smsd', 'resetfrequency', 'number', t('form.resetfrequency')],
                ['smsd', 'hardresetfrequency', 'number', t('form.hardresetfrequency')],
                ['smsd', 'loopsleep', 'number', t('form.loopsleep')],
                ['smsd', 'commtimeout', 'number', t('form.commtimeout')],
                ['smsd', 'sendtimeout', 'number', t('form.sendtimeout')],
            ],
            t('form.card_log') => [
                ['smsd', 'debuglevel', 'select', t('form.debuglevel'), ['' => t('form.debug_default'), '0' => t('form.debug_0'), '1' => t('form.debug_1'),
                    '2' => t('form.debug_2'), '3' => t('form.debug_3'), '255' => t('form.debug_255')]],
                ['smsd', 'logfile', 'text', t('form.logfile')],
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
                    $errors[$name] = t('config.err_int');
                    continue;
                }
                // Wartość spoza listy jest dozwolona, jeśli to niezmieniona wartość z pliku (np. debuglevel = 4)
                if ($type === 'select' && !array_key_exists($value, $f[4]) && $value !== (string) $old) {
                    $errors[$name] = t('config.err_value');
                    continue;
                }
                if (preg_match('/[\r\n]/', $value)) {
                    $errors[$name] = t('config.err_newline');
                    continue;
                }
                if ((string) $old === $value) {
                    continue;
                }
                $copy->set($section, $key, $value === '' ? null : $value);
                $show = static fn (?string $v) => $type === 'password' && $v !== null && $v !== '' ? '****' : ($v === null || $v === '' ? t('config.default_value') : $v);
                $changes[] = $key . ' ' . $show($old) . ' → ' . $show($value === '' ? null : $value);
            }
        }
        return [$copy, $changes, $errors];
    }
}
