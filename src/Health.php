<?php
declare(strict_types=1);

/**
 * Kontrola zdrowia – wspólna dla pulpitu (rozdz. 2.2) i `bin/smsgui check` (rozdz. 2.16).
 * Każdy wynik: level ok|warn|err, text (fragmenty w `…` są pokazywane jako kod), hint – co poprawić.
 */
final class Health
{
    /** @return list<array{level:string,text:string,hint:string,id:string}> */
    public static function run(bool $environment = false): array
    {
        $r = [];
        $add = static function (string $id, string $level, string $text, string $hint = '') use (&$r): void {
            $r[] = ['id' => $id, 'level' => $level, 'text' => $text, 'hint' => $hint];
        };

        if ($environment) {
            $add('php', version_compare(PHP_VERSION, '8.5.0', '>=') ? 'ok' : 'err', 'PHP ' . PHP_VERSION, 'wymagane PHP 8.5 lub nowsze');
            $missing = array_filter(['pdo_mysql', 'mbstring'], static fn ($x) => !extension_loaded($x));
            $add('ext', $missing === [] ? 'ok' : 'err', $missing === [] ? 'Rozszerzenia PHP: pdo_mysql, mbstring'
                : 'Brak rozszerzeń PHP: ' . implode(', ', $missing), 'sudo apt install php-mysql php-mbstring');
            $local = getenv('SMSGUI_CONFIG') ?: APP_ROOT . '/config/config.php';
            $add('config', is_file($local) ? 'ok' : 'warn', is_file($local) ? 'Konfiguracja `config/config.php`' : 'Brak `config/config.php` – używane są wartości domyślne',
                'skopiuj config/config.example.php i uzupełnij hasło do bazy');
        }

        try {
            Db::pdo();
        } catch (Throwable $e) {
            $add('db', 'err', 'Brak połączenia z bazą: ' . $e->getMessage(), 'sprawdź usługę MariaDB i db.dsn / db.user / db.password w config.php');
            return $r;
        }
        if ($environment) {
            $add('db', 'ok', 'Połączenie z bazą panelu, schemat w wersji ' . Db::schemaVersion());
        }

        // Baza Gammu: wersja schematu i silnik tabel
        try {
            $version = (int) Db::val('SELECT Version FROM {g}.gammu');
            $add('gammu-schema', $version === 17 ? 'ok' : 'err', 'Baza `' . cfg('gammu_db') . '`, schemat w wersji ' . $version,
                'panel wymaga schematu 17 (Gammu 1.42)');
        } catch (Throwable) {
            $add('gammu-schema', 'err', 'Brak bazy Gammu `' . cfg('gammu_db') . '` lub tabeli `gammu`', 'utwórz tabele z mysql.sql (rozdz. 6.2, krok 2)');
            return $r;
        }
        $engines = Db::all("SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN ('gammu','inbox','outbox','outbox_multipart','phones','sentitems')", [cfg('gammu_db')]);
        $bad = array_column(array_filter($engines, static fn ($x) => strcasecmp((string) $x['e'], 'InnoDB') !== 0), 't');
        $add('engine', $bad === [] ? 'ok' : 'err', $bad === [] ? 'Tabele Gammu w silniku InnoDB'
            : 'Tabele Gammu bez transakcji (MyISAM): ' . implode(', ', $bad) . ' – ryzyko wysłania niepełnego SMS wieloczęściowego',
            'ALTER TABLE <tabela> ENGINE=InnoDB dla każdej tabeli bazy Gammu');

        $diff = abs((int) ts((string) Db::val('SELECT NOW()')) - time());
        $add('time', $diff <= 60 ? 'ok' : 'warn', 'Czas bazy ' . ($diff <= 60 ? 'zgodny' : 'niezgodny') . " z PHP (różnica $diff s)",
            'ustaw tę samą strefę czasową w systemie, MariaDB i config.php (timezone)');

        // gammu-smsdrc
        $path = GammuConf::path();
        $conf = GammuConf::load(true);
        if ($conf === null) {
            $add('conf', 'err', '`' . $path . '` nieczytelny', 'chown root:www-data ' . $path . ' && chmod 0660 ' . $path);
        } else {
            $writable = is_writable($path);
            $service = strtolower((string) $conf->get('smsd', 'service'));
            $db = $conf->get('smsd', 'database');
            $ok = $writable && $service === 'sql' && $db === (string) cfg('gammu_db');
            $text = '`' . basename($path) . '` ' . ($writable ? 'czytelny i zapisywalny' : 'tylko do odczytu')
                . ', `service = ' . ($service ?: '?') . '`' . ($db !== (string) cfg('gammu_db') ? ', baza `' . ($db ?? '?') . '` ≠ `' . cfg('gammu_db') . '`' : '');
            $add('conf', $ok ? 'ok' : ($service !== 'sql' ? 'err' : 'warn'), $text, 'panel wymaga service = sql i zapisu pliku przez www-data (rozdz. 5.2)');

            $drd = $conf->get('smsd', 'deliveryreportdelay');
            if ($drd === null || (int) $drd < 3600) {
                $add('drd', 'warn', '`DeliveryReportDelay = ' . ($drd ?? '600') . '` – zalecane co najmniej 3600 s',
                    'raport od telefonu wyłączonego dłużej niż ten czas nie zostanie dopasowany; zalecane 172800 (2 dni)');
            }
            if ($conf->get('smsd', 'includenumbersfile') !== null || in_array('include_numbers', $conf->sections(), true)) {
                $add('include', 'warn', 'Ustawiona lista dozwolonych numerów (`IncludeNumbers…`) – czarna lista nie działa',
                    'usuń IncludeNumbersFile / [include_numbers] z gammu-smsdrc');
            }
        }

        // Czarna lista
        $blocked = (int) Db::val('SELECT COUNT(*) FROM blocked_numbers');
        if ($blocked > 0 || is_file((string) cfg('blocklist_file'))) {
            $sync = Blocklist::inSync();
            $add('blocklist', $sync ? 'ok' : 'warn', $sync ? "Czarna lista zgodna z plikiem ($blocked " . plural($blocked, 'numer', 'numery', 'numerów') . ')'
                : 'Plik czarnej listy niezgodny z bazą panelu', 'zapisz listę ponownie na ekranie „Zablokowane numery”');
            if ($blocked > 0 && $conf !== null && $conf->get('smsd', 'excludenumbersfile') === null) {
                $add('blocklist-conf', 'warn', 'Czarna lista nie jest włączona w gammu-smsdrc (`ExcludeNumbersFile`)', 'włącz ją na ekranie „Zablokowane numery”');
            }
        }

        // Log Gammu
        $log = Service::logPath();
        if ($environment || ($log !== null && !is_readable($log))) {
            $add('log', $log !== null && is_readable($log) ? 'ok' : 'warn', $log === null ? 'Brak `logfile` w gammu-smsdrc'
                : 'Log Gammu `' . $log . '` ' . (is_readable($log) ? 'czytelny' : 'nieczytelny'), 'grupa www-data i prawo g+r dla pliku logu');
        }

        // Usługa, proces w tle, modem, kolejka
        $service = $environment ? Service::refreshStatus() : Status::service();
        $add('service', $service === 'active' ? 'ok' : 'err', 'Usługa Gammu SMSD: ' . $service, 'sprawdź log Gammu i zakładkę „Usługa”');

        $worker = ts(Settings::get('worker_seen_at'));
        $add('worker', $worker !== null && time() - $worker <= 60 ? 'ok' : 'err', $worker === null ? 'Proces w tle nie był uruchomiony'
            : 'Proces w tle: ostatni przebieg ' . fmt_ago(Settings::get('worker_seen_at')), 'sudo systemctl enable --now smsgui-worker');
        $err = (string) Settings::get('last_sync_error', '');
        if ($err !== '') {
            $add('sync', 'warn', 'Błąd ostatniej synchronizacji: ' . $err, 'szczegóły w logu aplikacji');
        }

        $m = Status::modem();
        if (!Status::modemAvailable($m)) {
            $add('modem', 'warn', $m === null ? 'Modem jeszcze nie zgłosił się w tabeli `phones`'
                : 'Modem ' . $m['modem'] . ' niedostępny – ostatnia aktualizacja ' . fmt_ago($m['gammu_updated_at']), 'sprawdź log Gammu i port modemu');
        } elseif ($environment) {
            $add('modem', 'ok', 'Modem ' . $m['modem'] . ' dostępny, sygnał ' . $m['signal_pct'] . ' %');
        }

        $stuck = (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND status = 'queued' AND retries = 0
            AND COALESCE(scheduled_at, created_at) < ?", [now_db(-900)]);
        $add('queue', $stuck === 0 ? 'ok' : 'warn', $stuck === 0 ? 'Brak wiadomości czekających w kolejce > 15 min'
            : "$stuck " . plural($stuck, 'wiadomość czeka', 'wiadomości czekają', 'wiadomości czeka') . ' w kolejce dłużej niż 15 min',
            'Gammu nie wysyła: sprawdź usługę, modem i SenderID');
        return $r;
    }

    public static function worst(array $results): string
    {
        $levels = array_column($results, 'level');
        return in_array('err', $levels, true) ? 'err' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /** Tekst z `kodem` jako HTML. */
    public static function html(string $text): string
    {
        return preg_replace('/`([^`]+)`/', '<code>$1</code>', e($text));
    }
}
