<?php
// Konfiguracja Gammu (rozdz. 2.10): Ustawienia, Edytor pliku, Kopie zapasowe, Usługa
$tabs = ['form', 'editor', 'backups', 'service'];
$tab = in_array(input('tab'), $tabs, true) ? input('tab') : 'form';
$conf = GammuConf::load(true);
$path = GammuConf::path();

// Oczekiwanie na modem po restarcie (htmx co 2 s, najdłużej 30 s)
if (input('fragment') === 'modemwait') {
    echo view('config-modemwait', ['op' => $_SESSION['service_op'] ?? null]);
    exit;
}

$fieldErrors = [];
$editorText = null;
$editorValidation = null;
if (is_post()) {
    $action = input('action');
    if ($action === 'confirm') {
        redirect(ConfigSave::confirm(input('token'), input('after')));
    }
    if ($action === 'discard') {
        unset($_SESSION['conf_pending']);
        redirect(url('config', ['tab' => $tab]));
    }
    if ($action === 'reload') {
        [$code, $out] = Service::reload();
        $_SESSION['service_op'] = ['cmd' => (string) cfg('service.reload_cmd'), 'code' => $code, 'out' => $out, 'started' => now_db(), 'reason' => 'przeładowanie'];
        flash($code === 0 ? 'ok' : 'err', $code === 0 ? 'Przeładowano konfigurację Gammu.' : 'Przeładowanie nie powiodło się.', $out);
        redirect(url('config', ['tab' => 'service']));
    }
    if ($action === 'restart') {
        redirect(Service::restartAndWatch('restart z panelu'));
    }
    if ($conf === null) {
        flash('err', 'Nie można odczytać ' . $path . '.');
        redirect(url('config', ['tab' => $tab]));
    }
    if ($action === 'form') {
        [$new, $changes, $fieldErrors] = GammuConfForm::apply($conf, $_POST);
        if ($fieldErrors === []) {
            if ($changes === []) {
                flash('info', 'Brak zmian do zapisania.');
                redirect(url('config'));
            }
            ConfigSave::propose($new->text(), 'formularz: ' . implode(', ', $changes), 'form');
        }
    }
    if ($action === 'editor' || $action === 'validate') {
        $posted = str_replace("\r\n", "\n", (string) ($_POST['conf'] ?? ''));
        $editorText = $posted;
        $full = GammuConf::unmask($posted, $conf->text());
        if ($full !== '' && !str_ends_with($full, "\n")) {
            $full .= "\n";
        }
        if ($action === 'editor') {
            ConfigSave::propose($full, 'edytor pliku', 'editor');
        }
        $editorValidation = GammuConf::parse($full)->validate($conf);
    }
    if ($action === 'restore') {
        $name = input('name');
        $text = GammuConf::readBackup($name);
        if ($text === null) {
            flash('err', 'Nie znaleziono kopii.');
            redirect(url('config', ['tab' => 'backups']));
        }
        ConfigSave::propose($text, 'przywrócenie kopii ' . $name, 'backups');
    }
}

$data = ['title' => 'Konfiguracja Gammu', 'nav' => 'config', 'tab' => $tab, 'conf' => $conf, 'path' => $path,
    'mtime' => is_file($path) ? filemtime($path) : null, 'backups' => GammuConf::backups(),
    'pending' => input('confirm') !== '' ? ConfigSave::pending() : null, 'fieldErrors' => $fieldErrors];

if ($tab === 'form') {
    $data['values'] = is_post() ? $_POST : [];
    $data['ports'] = GammuConfForm::ports();
} elseif ($tab === 'editor' && $conf !== null) {
    $data['editorText'] = $editorText ?? GammuConf::mask($conf->text());
    $data['validation'] = $editorValidation ?? $conf->validate();
} elseif ($tab === 'backups') {
    $name = input('view') ?: input('compare');
    if ($name !== '' && ($text = GammuConf::readBackup($name)) !== null) {
        $data['shown'] = ['name' => $name, 'mode' => input('view') !== '' ? 'view' : 'compare', 'text' => GammuConf::mask($text),
            'diff' => $conf ? Diff::context(Diff::lines(GammuConf::mask($text), GammuConf::mask($conf->text())), 3) : []];
    }
} else {
    [$code, $out] = Service::run((string) cfg('service.status_cmd'));
    $data['statusText'] = $out;
    $data['active'] = trim(strtok($out, "\n") ?: '') === 'active';
    $data['op'] = $_SESSION['service_op'] ?? null;
    $data['wait'] = input('wait') !== '';
    $log = Service::logPath();
    $data['logLines'] = $log !== null && is_readable($log) ? Service::tail($log, 20) : [];
}
render('config', $data);
