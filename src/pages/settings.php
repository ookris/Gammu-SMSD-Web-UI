<?php
// Ustawienia panelu (rozdz. 2.15) – zapisywane w bazie; ustawienia systemowe tylko do odczytu
$errors = [];
if (is_post()) {
    $from = input('window_from');
    $to = input('window_to');
    $int = static fn (string $k, int $min, int $max) => is_numeric(input($k)) && (int) input($k) >= $min && (int) input($k) <= $max;
    if (!preg_match('/^\d\d:\d\d$/', $from) || !preg_match('/^\d\d:\d\d$/', $to) || $from >= $to) {
        $errors['window'] = 'Okno wysyłki: godzina „od” musi być wcześniejsza niż „do” – okno nie może przechodzić przez północ (ograniczenie Gammu).';
    }
    foreach (['throttle_per_min' => [0, 60], 'session_hours' => [1, 720], 'backup_keep' => [1, 500], 'cleanup_days' => [0, 3650]] as $k => [$min, $max]) {
        if (!$int($k, $min, $max)) {
            $errors[$k] = "Podaj liczbę od $min do $max.";
        }
    }
    $codes = [];
    foreach (input_list('ussd_name') as $i => $name) {
        $code = str_replace(' ', '', (string) ($_POST['ussd_code'][$i] ?? ''));
        if ($code === '') {
            continue;
        }
        if (!preg_match('/^[0-9*#+]{1,20}$/', $code)) {
            $errors['ussd'] = 'Kod USSD „' . $code . '” może zawierać tylko cyfry, * i #.';
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
        Settings::set('window_from', $from);
        Settings::set('window_to', $to);
        Settings::set('ussd_codes', $codes);
        flash('ok', 'Zapisano ustawienia panelu.');
        $calls = isset($_POST['calls_enabled']);
        if ($calls !== Calls::enabledInConf()) {
            ConfigSave::propose(Calls::confChange($calls)->text(), ($calls ? 'włączenie' : 'wyłączenie') . ' zapisywania połączeń (HangupCalls, RunOnIncomingCall)', 'form');
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
    $system[$k] = $val === null ? '(z gammu-smsdrc)' : (is_bool($val) ? ($val ? 'true' : 'false') : (string) $val);
}
render('settings', ['title' => 'Ustawienia panelu', 'nav' => 'settings', 'v' => $v, 'errors' => $errors,
    'codes' => is_post() ? [] : Settings::json('ussd_codes'), 'callsEnabled' => Calls::enabledInConf(), 'system' => $system]);
