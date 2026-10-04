<?php
declare(strict_types=1);

/**
 * Kontrola zdrowia – wspólna dla pulpitu i `bin/smsgui check`.
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
            $add('php', version_compare(PHP_VERSION, '8.5.0', '>=') ? 'ok' : 'err', 'PHP ' . PHP_VERSION, t('health.php_hint'));
            $missing = array_filter(['pdo_mysql', 'mbstring'], static fn ($x) => !extension_loaded($x));
            $add('ext', $missing === [] ? 'ok' : 'err', $missing === [] ? t('health.ext_ok')
                : t('health.ext_missing', ['list' => implode(', ', $missing)]), 'sudo apt install php-mysql php-mbstring');
            $local = getenv('SMSGUI_CONFIG') ?: APP_ROOT . '/config/config.php';
            $add('config', is_file($local) ? 'ok' : 'warn', t(is_file($local) ? 'health.config_ok' : 'health.config_missing'), t('health.config_hint'));
        }

        try {
            Db::pdo();
        } catch (Throwable $e) {
            $add('db', 'err', t('health.db_err', ['error' => $e->getMessage()]), t('health.db_hint'));
            return $r;
        }
        if ($environment) {
            $add('db', 'ok', t('health.db_ok', ['version' => Db::schemaVersion()]));
        }

        // Baza Gammu: wersja schematu i silnik tabel
        try {
            $version = (int) Db::val('SELECT Version FROM {g}.gammu');
            $add('gammu-schema', $version === 17 ? 'ok' : 'err', t('health.gammu_schema', ['db' => cfg('gammu_db'), 'version' => $version]),
                t('health.gammu_schema_hint'));
        } catch (Throwable) {
            $add('gammu-schema', 'err', t('health.gammu_missing', ['db' => cfg('gammu_db')]), t('health.gammu_missing_hint'));
            return $r;
        }
        $engines = Db::all("SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN ('gammu','inbox','outbox','outbox_multipart','phones','sentitems')", [cfg('gammu_db')]);
        $bad = array_column(array_filter($engines, static fn ($x) => strcasecmp((string) $x['e'], 'InnoDB') !== 0), 't');
        $add('engine', $bad === [] ? 'ok' : 'err', $bad === [] ? t('health.engine_ok') : t('health.engine_bad', ['list' => implode(', ', $bad)]), t('health.engine_hint'));

        $diff = abs((int) ts((string) Db::val('SELECT NOW()')) - time());
        $add('time', $diff <= 60 ? 'ok' : 'warn', t($diff <= 60 ? 'health.time_ok' : 'health.time_bad', ['n' => $diff]), t('health.time_hint'));

        // gammu-smsdrc
        $path = GammuConf::path();
        $conf = GammuConf::load(true);
        if ($conf === null) {
            $add('conf', 'err', t('health.conf_unreadable', ['path' => $path]), 'chown root:www-data ' . $path . ' && chmod 0660 ' . $path);
        } else {
            $writable = is_writable($path);
            $service = strtolower((string) $conf->get('smsd', 'service'));
            $db = $conf->get('smsd', 'database');
            $ok = $writable && $service === 'sql' && $db === (string) cfg('gammu_db');
            $text = t($writable ? 'health.conf_rw' : 'health.conf_ro', ['file' => basename($path), 'service' => $service ?: '?'])
                . ($db !== (string) cfg('gammu_db') ? t('health.conf_db', ['db' => $db ?? '?', 'expected' => cfg('gammu_db')]) : '');
            $add('conf', $ok ? 'ok' : ($service !== 'sql' ? 'err' : 'warn'), $text, t('health.conf_hint'));

            $drd = $conf->get('smsd', 'deliveryreportdelay');
            if ($drd === null || (int) $drd < 3600) {
                $add('drd', 'warn', t('health.drd', ['value' => $drd ?? '600']), t('health.drd_hint'));
            }
            if ($conf->get('smsd', 'includenumbersfile') !== null || in_array('include_numbers', $conf->sections(), true)) {
                $add('include', 'warn', t('health.include'), t('health.include_hint'));
            }
        }

        // Czarna lista
        $blocked = (int) Db::val('SELECT COUNT(*) FROM blocked_numbers');
        if ($blocked > 0 || is_file((string) cfg('blocklist_file'))) {
            $sync = Blocklist::inSync();
            $add('blocklist', $sync ? 'ok' : 'warn', $sync ? t('health.blocklist_ok', ['count' => tn('health.blocklist_count', $blocked)])
                : t('health.blocklist_bad'), t('health.blocklist_hint'));
            if ($blocked > 0 && $conf !== null && $conf->get('smsd', 'excludenumbersfile') === null) {
                $add('blocklist-conf', 'warn', t('health.blocklist_conf'), t('health.blocklist_conf_hint'));
            }
        }

        // Log Gammu
        $log = Service::logPath();
        if ($environment || ($log !== null && !is_readable($log))) {
            $add('log', $log !== null && is_readable($log) ? 'ok' : 'warn', $log === null ? t('health.log_missing')
                : t(is_readable($log) ? 'health.log_ok' : 'health.log_bad', ['path' => $log]), t('health.log_hint'));
        }

        // Usługa, proces w tle, modem, kolejka
        $service = $environment ? Service::refreshStatus() : Status::service();
        $add('service', $service === 'active' ? 'ok' : 'err', t('health.service', ['state' => $service]), t('health.service_hint'));

        $worker = ts(Settings::get('worker_seen_at'));
        $add('worker', $worker !== null && time() - $worker <= 60 ? 'ok' : 'err', $worker === null ? t('health.worker_never')
            : t('health.worker', ['ago' => fmt_ago(Settings::get('worker_seen_at'))]), 'sudo systemctl enable --now smsgui-worker');
        $err = (string) Settings::get('last_sync_error', '');
        if ($err !== '') {
            $add('sync', 'warn', t('health.sync', ['error' => $err]), t('health.sync_hint'));
        }

        $m = Status::modem();
        if (!Status::modemAvailable($m)) {
            $add('modem', 'warn', $m === null ? t('health.modem_never')
                : t('health.modem_down', ['modem' => $m['modem'], 'ago' => fmt_ago($m['gammu_updated_at'])]), t('health.modem_hint'));
        } elseif ($environment) {
            $add('modem', 'ok', t('health.modem_ok', ['modem' => $m['modem'], 'pct' => $m['signal_pct']]));
        }

        $stuck = (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND status = 'queued' AND retries = 0
            AND COALESCE(scheduled_at, created_at) < ?", [now_db(-900)]);
        $add('queue', $stuck === 0 ? 'ok' : 'warn', $stuck === 0 ? t('health.queue_ok') : tn('health.queue_stuck', $stuck), t('health.queue_hint'));
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
