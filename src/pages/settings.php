<?php
// Ustawienia panelu – zapisywane w bazie; ustawienia systemowe tylko do odczytu
$errors = [];
if (is_post() && isset($_POST['backup_export'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="smsgui-' . date('Y-m-d') . '.json"');
    echo Backup::export();
    exit;
}
if (is_post() && isset($_POST['backup_import'])) {
    $f = $_FILES['backup'] ?? null;
    if ($f === null || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > Backup::MAX_BYTES) {
        flash('err', t('blocklist.upload_failed'), t('backup.upload_hint'));
    } else {
        try {
            $n = Backup::import((string) file_get_contents($f['tmp_name']));
            flash('ok', t('backup.imported'), t('backup.imported_text', $n));
        } catch (InvalidArgumentException $e) {
            flash('err', t('backup.import_failed'), $e->getMessage());
        }
    }
    redirect(url('settings'));
}
if (is_post()) {
    $from = input('window_from');
    $to = input('window_to');
    $int = static fn (string $k, int $min, int $max) => is_numeric(input($k)) && (int) input($k) >= $min && (int) input($k) <= $max;
    if (!Recipients::validTime($from) || !Recipients::validTime($to)) {
        $errors['window'] = t('settings.err_window_format');
    } elseif ($from >= $to) {
        $errors['window'] = t('settings.err_window_order');
    }
    foreach (['throttle_per_min' => [0, 60], 'session_hours' => [1, 720], 'backup_keep' => [1, 500], 'cleanup_days' => [0, 3650]] as $k => [$min, $max]) {
        if (!$int($k, $min, $max)) {
            $errors[$k] = t('settings.err_range', ['min' => $min, 'max' => $max]);
        }
    }
    $bcode = str_replace(' ', '', input('balance_code'));
    if ($bcode !== '' && !preg_match('/^[0-9*#+]{1,20}$/', $bcode)) {
        $errors['balance_code'] = t('settings.err_ussd', ['code' => $bcode]);
    }
    if (!Balance::validRegex(trim(input('balance_regex')))) {
        $errors['balance_regex'] = t('settings.err_regex');
    }
    foreach (['balance_hours' => [0, 720], 'monthly_limit' => [0, 1000000]] as $k => [$min, $max]) {
        if (!$int($k, $min, $max)) {
            $errors[$k] = t('settings.err_range', ['min' => $min, 'max' => $max]);
        }
    }
    $threshold = str_replace(',', '.', trim(input('balance_threshold')));
    if ($threshold === '') {
        $threshold = '0';
    }
    if (!is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 100000) {
        $errors['balance_threshold'] = t('settings.err_range', ['min' => 0, 'max' => 100000]);
    }
    // Pary nazwa–kod po indeksach z formularza (puste nazwy nie mogą przesunąć kodów)
    $codes = [];
    $names = is_array($_POST['ussd_name'] ?? null) ? $_POST['ussd_name'] : [];
    foreach (is_array($_POST['ussd_code'] ?? null) ? $_POST['ussd_code'] : [] as $i => $code) {
        $code = str_replace(' ', '', is_string($code) ? $code : '');
        $name = is_string($names[$i] ?? null) ? trim($names[$i]) : '';
        if ($code === '') {
            continue;
        }
        if (!preg_match('/^[0-9*#+]{1,20}$/', $code)) {
            $errors['ussd'] = t('settings.err_ussd', ['code' => $code]);
        }
        $codes[] = ['name' => mb_substr($name, 0, 40), 'code' => $code];
    }
    if ($errors === []) {
        foreach (['report_default', 'translit_default', 'window_enabled'] as $k) {
            Settings::set($k, isset($_POST[$k]));
        }
        foreach (['throttle_per_min', 'session_hours', 'backup_keep', 'cleanup_days'] as $k) {
            Settings::set($k, (int) input($k));
        }
        Settings::set('balance_code', $bcode);
        Settings::set('balance_regex', trim(input('balance_regex')));
        Settings::set('balance_hours', (int) input('balance_hours'));
        Settings::set('monthly_limit', (int) input('monthly_limit'));
        Settings::set('balance_threshold', (string) round((float) $threshold, 2));
        Settings::set('validity_default', Outbox::validity(input('validity_default')));
        Settings::set('window_from', $from);
        Settings::set('window_to', $to);
        Settings::set('ussd_codes', $codes);
        if (isset(LANGS[input('lang')])) {
            Settings::set('lang', input('lang'));
            lang(input('lang')); // komunikat już w nowym języku
        }
        flash('ok', t('settings.saved'));
        $calls = isset($_POST['calls_enabled']);
        if ($calls !== Calls::enabledInConf()) {
            ConfigSave::propose(Calls::confChange($calls)->text(), msg_key($calls ? 'settings.calls_enable' : 'settings.calls_disable'), 'form');
        }
        redirect(url('settings'));
    }
}
$v = static fn (string $k) => is_post() ? input($k) : (string) Settings::get($k);
$system = [];
foreach (['db.dsn', 'db.user', 'gammu_db', 'gammu_conf', 'blocklist_file', 'backup_dir', 'log_path', 'session_path', 'gammu_log', 'serial_dir',
    'service.status_cmd', 'service.reload_cmd', 'service.restart_cmd', 'hook.command', 'creator_id', 'gammu_rows', 'worker_interval',
    'default_country_code', 'national_number_length', 'max_recipients', 'timezone'] as $k) {
    $val = cfg($k);
    $system[$k] = $val === null ? t('settings.from_gammu') : (is_bool($val) ? ($val ? 'true' : 'false') : (string) $val);
}
// Po błędzie walidacji formularz pokazuje kody USSD z żądania – kolejny zapis ich nie wyczyści
render('settings', ['title' => t('nav.settings'), 'nav' => 'settings', 'v' => $v, 'errors' => $errors,
    'codes' => is_post() ? $codes : Settings::json('ussd_codes'), 'callsEnabled' => Calls::enabledInConf(), 'system' => $system]);
