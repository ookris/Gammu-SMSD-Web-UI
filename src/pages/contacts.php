<?php
// Kontakty – książka telefoniczna
if (input('export') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . t('contacts.csv_name') . '-' . date('Y-m-d') . '.csv"');
    echo Contacts::csvExport();
    exit;
}

// Import CSV: plik → podgląd (w sesji) → potwierdzenie
if (is_post() && isset($_FILES['csv'])) {
    $f = $_FILES['csv'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        flash('err', t('blocklist.upload_failed'), t('contacts.upload_hint'));
        redirect(url('contacts', ['import' => 1]));
    }
    $_SESSION['csv_import'] = Contacts::csvPreview((string) file_get_contents($f['tmp_name']));
    redirect(url('contacts', ['import' => 1]));
}
if (is_post() && isset($_POST['import_confirm'])) {
    $preview = $_SESSION['csv_import'] ?? null;
    unset($_SESSION['csv_import']);
    if ($preview !== null) {
        [$new, $upd] = Contacts::csvImport($preview['rows']);
        flash('ok', t('contacts.imported'), t($preview['errors'] ? 'contacts.imported_skipped' : 'contacts.imported_text', ['new' => $new, 'updated' => $upd, 'invalid' => count($preview['errors'])]));
    }
    redirect(url('contacts'));
}
if (input('import') !== '') {
    if (isset($_GET['cancel'])) {
        unset($_SESSION['csv_import']);
        redirect(url('contacts'));
    }
    render('contacts-import', ['title' => t('contacts.import_title'), 'nav' => 'contacts', 'preview' => $_SESSION['csv_import'] ?? null]);
}

// Akcje zbiorcze
if (is_post()) {
    $ids = input_ids();
    $gid = (int) input('group_id');
    $n = count($ids);
    switch (input('action')) {
        case 'sms':
            redirect(url('compose', ['contacts' => $ids]));
        case 'add_group':
            if ($gid > 0) {
                Contacts::addToGroup($ids, $gid);
                flash('ok', tn('contacts.added_to_group', $n));
            }
            break;
        case 'remove_group':
            if ($gid > 0) {
                Contacts::removeFromGroup($ids, $gid);
                flash('ok', tn('contacts.removed_from_group', $n));
            }
            break;
        case 'delete':
            Contacts::delete($ids);
            flash('ok', tn('contacts.deleted', $n), t('contacts.deleted_text'));
            break;
    }
    back(url('contacts'));
}

$q = input('q');
$group = input('group');
$page = Ui::page();
[$rows, $total] = Contacts::search($q, $group, $page);
$data = ['rows' => $rows, 'total' => $total, 'page' => $page, 'q' => $q, 'group' => $group, 'groups' => Contacts::groups(),
    'memberOf' => Contacts::groupsOf(array_map('intval', array_column($rows, 'id')))];
if (is_htmx()) {
    echo view('contacts-results', $data);
    exit;
}
render('contacts', $data + ['title' => t('nav.contacts'), 'nav' => 'contacts', 'count' => (int) Db::val('SELECT COUNT(*) FROM contacts'),
    'noGroup' => (int) Db::val('SELECT COUNT(*) FROM contacts c WHERE NOT EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = c.id)')]);
