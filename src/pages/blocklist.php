<?php
// Zablokowane numery (rozdz. 2.14)
if (input('export') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . t('blocklist.csv_name') . '-' . date('Y-m-d') . '.csv"');
    echo "\u{FEFF}" . t('blocklist.csv_header') . "\r\n";
    foreach (Db::all('SELECT phone, note FROM blocked_numbers ORDER BY phone') as $r) {
        echo Contacts::csvLine([Phone::isAlpha($r['phone']) ? $r['phone'] : Phone::toGammu($r['phone']), $r['note']]);
    }
    exit;
}
if (is_post()) {
    if (isset($_POST['enable'])) {
        Blocklist::write();
        $conf = GammuConf::load(true) ?? throw new RuntimeException(t('file.cannot_read', ['path' => GammuConf::path()]));
        $copy = GammuConf::parse($conf->text());
        $copy->set('smsd', 'excludenumbersfile', Blocklist::path());
        ConfigSave::propose($copy->text(), t('blocklist.enable_note'), 'form');
    }
    if (isset($_FILES['csv']) && ($_FILES['csv']['error'] !== UPLOAD_ERR_OK || $_FILES['csv']['size'] > 5 * 1024 * 1024)) {
        flash('err', t('blocklist.upload_failed'), t($_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE ? 'blocklist.choose_file' : 'blocklist.file_too_big'));
        redirect(url('blocklist'));
    }
    if (isset($_FILES['csv'])) {
        $n = 0;
        foreach (Contacts::csvRows((string) file_get_contents($_FILES['csv']['tmp_name'])) as $i => $r) {
            if ($i === 0 && in_array(mb_strtolower($r[0] ?? ''), ['numer', 'number'], true)) {
                continue;
            }
            [$p, $err] = Blocklist::add($r[0] ?? '', $r[1] ?? '');
            $n += (int) ($err === null);
        }
        flash('ok', tn('blocklist.imported', $n), Blocklist::apply());
        redirect(url('blocklist'));
    }
    if (($id = (int) input('unblock')) > 0) {
        $phone = Db::val('SELECT phone FROM blocked_numbers WHERE id = ?', [$id]);
        Db::exec('DELETE FROM blocked_numbers WHERE id = ?', [$id]);
        flash('ok', t('blocklist.unblocked', ['who' => Phone::format((string) $phone)]), Blocklist::apply());
        redirect(url('blocklist'));
    }
    if (input('phone') !== '') {
        Blocklist::blockFromPanel(input('phone'), input('note'));
        redirect(url('blocklist'));
    }
    redirect(url('blocklist'));
}
$q = input('q');
$params = [];
$where = '1=1';
if ($q !== '') {
    $where = '(b.phone LIKE ? OR b.note LIKE ? OR c.name LIKE ?)';
    $digits = preg_replace('/[^0-9]/', '', $q);
    $params = ['%' . ($digits !== '' ? ltrim($digits, '0') : $q) . '%', "%$q%", "%$q%"];
}
$rows = Db::all("SELECT b.*, c.id AS contact_id, c.name AS contact_name FROM blocked_numbers b LEFT JOIN contacts c ON c.phone = b.phone WHERE $where ORDER BY b.created_at DESC", $params);
render('blocklist', ['title' => t('nav.blocklist'), 'nav' => 'blocklist', 'rows' => $rows, 'q' => $q, 'enabled' => Blocklist::enabledInConf(),
    'inSync' => Blocklist::inSync(), 'written' => Settings::get('blocklist_written_at'), 'reload' => Settings::get('blocklist_reload')]);
