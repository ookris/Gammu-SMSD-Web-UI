<?php
declare(strict_types=1);

/** Kroki instalatora korzystające z parsera panelu: `smsgui setup gammu`. */
final class Setup
{
    /**
     * Ustawia w gammu-smsdrc to, czego wymaga panel; zmienia tylko potrzebne linie, przed zmianą robi kopię
     * /etc/gammu-smsdrc.smsgui-RRRRMMDD-GGMMSS. Opcje: --device, --connection, --phoneid, --pin, --db-host, --db-user,
     * --db-password, --db-name, --logfile, --force-sql (przestawienie z backendu files).
     */
    public static function gammu(array $o): int
    {
        $path = GammuConf::path();
        $exists = is_file($path);
        $old = $exists ? (string) file_get_contents($path) : '';
        $conf = GammuConf::parse($exists ? $old : "# Konfiguracja Gammu SMSD – utworzona przez Gammu SMSD Web UI\n[gammu]\n\n[smsd]\n");
        $service = strtolower((string) $conf->get('smsd', 'service'));
        if ($exists && $service !== '' && $service !== 'sql' && !isset($o['force-sql'])) {
            fwrite(STDERR, "gammu-smsdrc używa service = $service – uruchom z --force-sql, żeby przestawić na sql.\n");
            return 2;
        }
        $set = static function (string $section, string $key, ?string $value, bool $onlyIfMissing = false) use ($conf): void {
            if ($value === null || ($onlyIfMissing && $conf->get($section, $key) !== null)) {
                return;
            }
            $conf->set($section, $key, $value);
        };
        $set('gammu', 'device', $o['device'] ?? null);
        $conf->dropLegacyPort();
        $set('gammu', 'connection', $o['connection'] ?? 'at', !isset($o['connection']));
        $set('smsd', 'service', 'sql');
        $set('smsd', 'driver', 'native_mysql');
        $set('smsd', 'host', $o['db-host'] ?? 'localhost', !isset($o['db-host']));
        $set('smsd', 'user', $o['db-user'] ?? null);
        $set('smsd', 'password', $o['db-password'] ?? null);
        $set('smsd', 'database', $o['db-name'] ?? (string) cfg('gammu_db'));
        $set('smsd', 'phoneid', $o['phoneid'] ?? 'GSM1', !isset($o['phoneid']));
        if (isset($o['pin'])) {
            $conf->set('smsd', 'pin', $o['pin'] === '' ? null : (string) $o['pin']); // pusty = karta bez PIN-u
        }
        $set('smsd', 'logfile', $o['logfile'] ?? '/var/log/gammu-smsd/smsd.log', !isset($o['logfile']));
        $set('smsd', 'debuglevel', '1', true);
        $set('smsd', 'deliveryreportdelay', '172800', true);
        $new = $conf->text();
        if ($new === $old) {
            echo "gammu-smsdrc: bez zmian.\n";
            return 0;
        }
        if ($exists && self::backup($path, $old) === null) {
            return 1;
        }
        if (@file_put_contents($path, $new, LOCK_EX) === false) {
            fwrite(STDERR, "Nie można zapisać $path\n");
            return 1;
        }
        self::printChanges($old, $new);
        return 0;
    }

    /**
     * Konfiguracja dodatkowego modemu: kopia /etc/gammu-smsdrc z własnym urządzeniem, PhoneID i logiem, zapisana jako
     * /etc/gammu-smsdrc-<PhoneID> (prawa 0660, grupa www-data). Uruchamia ją usługa gammu-smsd@<PhoneID> (deploy/add-modem.sh).
     * Opcje: --phoneid, --device, --connection, --pin.
     */
    public static function modem(array $o): int
    {
        $id = (string) ($o['phoneid'] ?? '');
        $device = (string) ($o['device'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $id) || $device === '' || !str_starts_with($device, '/')) {
            fwrite(STDERR, "Użycie: smsgui setup modem --phoneid=ID (litery, cyfry, _ -) --device=/dev/… [--connection=at] [--pin=…]\n");
            return 1;
        }
        $base = GammuConf::path();
        $text = @file_get_contents($base);
        if ($text === false) {
            fwrite(STDERR, "Nie można odczytać $base\n");
            return 1;
        }
        $conf = GammuConf::parse($text);
        if (strcasecmp((string) $conf->get('smsd', 'phoneid'), $id) === 0) {
            fwrite(STDERR, "PhoneID $id ma już główny modem ($base).\n");
            return 1;
        }
        $path = $base . '-' . $id;
        if (file_exists($path)) {
            echo "$path już istnieje – zostawiam bez zmian (edytuj ręcznie).\n";
            return 2;
        }
        $conf->set('gammu', 'device', $device);
        $conf->dropLegacyPort();
        if (isset($o['connection'])) {
            $conf->set('gammu', 'connection', (string) $o['connection']);
        }
        $conf->set('smsd', 'phoneid', $id);
        $conf->set('smsd', 'logfile', '/var/log/gammu-smsd/' . $id . '.log');
        if (isset($o['pin'])) {
            $conf->set('smsd', 'pin', $o['pin'] === '' ? null : (string) $o['pin']);
        } elseif ($conf->get('smsd', 'pin') !== null) {
            // PIN głównej karty SIM nie pasuje do innej – zła próba przy starcie, a po trzech blokada karty
            $conf->set('smsd', 'pin', null);
            echo "PIN skopiowany z głównej konfiguracji został usunięty – jeśli karta ma PIN, podaj go opcją --pin=… .\n";
        }
        if ($conf->get('smsd', 'runonincomingcall') !== null) {
            $conf->set('smsd', 'runonincomingcall', cfg('hook.command') . ' --phone=' . escapeshellarg($id));
        }
        GammuConf::createFile($path, $conf->text(), 0660);
        if (function_exists('posix_getgrnam') && ($g = posix_getgrnam('www-data')) !== false) {
            @chgrp($path, $g['gid']);
        }
        echo "Zapisano $path\n";
        return 0;
    }

    /**
     * Deinstalacja: usuwa z gammu-smsdrc tylko ustawienia należące do panelu – hook `smsgui hook call` (wtedy też
     * HangupCalls, które włączył razem z nim) i ExcludeNumbersFile wskazujący plik panelu. Cudze wartości zostają.
     */
    public static function unhook(?string $path = null): int
    {
        $path ??= GammuConf::path();
        $old = @file_get_contents($path);
        if ($old === false) {
            echo "Brak $path – nic do zmiany.\n";
            return 0;
        }
        $conf = GammuConf::parse($old);
        $hook = (string) $conf->get('smsd', 'runonincomingcall');
        if ($hook !== '' && (str_starts_with($hook, (string) cfg('hook.command')) || preg_match('~bin/smsgui[\'"]? hook call~', $hook))) {
            $conf->set('smsd', 'runonincomingcall', null);
            $conf->set('smsd', 'hangupcalls', null);
        }
        if ($conf->get('smsd', 'excludenumbersfile') === Blocklist::path()) {
            $conf->set('smsd', 'excludenumbersfile', null);
        }
        $new = $conf->text();
        if ($new === $old) {
            echo "gammu-smsdrc: brak ustawień panelu.\n";
            return 0;
        }
        if (self::backup($path, $old) === null || @file_put_contents($path, $new, LOCK_EX) === false) {
            fwrite(STDERR, "Nie można zapisać $path\n");
            return 1;
        }
        self::printChanges($old, $new);
        return 0;
    }

    /** Kopia /etc/gammu-smsdrc.smsgui-RRRRMMDD-GGMMSS – od razu 0600, bez nadpisywania istniejącej. */
    private static function backup(string $path, string $content): ?string
    {
        $name = $path . '.smsgui-' . date('Ymd-His');
        for ($i = 1; file_exists($name); $i++) {
            $name = $path . '.smsgui-' . date('Ymd-His') . '-' . $i;
        }
        try {
            GammuConf::createFile($name, $content, 0600);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return null;
        }
        echo "Kopia: $name\n";
        return $name;
    }

    private static function printChanges(string $old, string $new): void
    {
        foreach (Diff::lines(GammuConf::mask($old), GammuConf::mask($new)) as [$op, $line]) {
            if ($op !== ' ') {
                echo "$op $line\n";
            }
        }
    }
}
