<?php
// Kontakty – książka telefoniczna (rozdz. 2.7)
if (input('export') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kontakty-' . date('Y-m-d') . '.csv"');
    echo Contacts::csvExport();
    exit;
}

// Import CSV: plik → podgląd (w sesji) → potwierdzenie
if (is_post() && isset($_FILES['csv'])) {
    $f = $_FILES['csv'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        flash('err', 'Nie udało się wczytać pliku.', 'Wybierz plik CSV (najwyżej 5 MB).');
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
        flash('ok', 'Zaimportowano kontakty.', "Nowe: $new, zaktualizowane: $upd" . ($preview['errors'] ? ', pominięte błędne wiersze: ' . count($preview['errors']) : '') . '.');
    }
    redirect(url('contacts'));
}
if (input('import') !== '') {
    if (isset($_GET['cancel'])) {
        unset($_SESSION['csv_import']);
        redirect(url('contacts'));
    }
    render('contacts-import', ['title' => 'Import kontaktów', 'nav' => 'contacts', 'preview' => $_SESSION['csv_import'] ?? null]);
}

// Akcje zbiorcze
if (is_post()) {
    $ids = input_ids();
    $gid = (int) input('group_id');
    $n = count($ids);
    $word = $n . ' ' . plural($n, 'kontakt', 'kontakty', 'kontaktów');
    switch (input('action')) {
        case 'sms':
            redirect(url('compose', ['contacts' => $ids]));
        case 'add_group':
            if ($gid > 0) {
                Contacts::addToGroup($ids, $gid);
                flash('ok', "Dodano $word do grupy.");
            }
            break;
        case 'remove_group':
            if ($gid > 0) {
                Contacts::removeFromGroup($ids, $gid);
                flash('ok', "Usunięto $word z grupy.");
            }
            break;
        case 'delete':
            Contacts::delete($ids);
            flash('ok', "Usunięto $word.", 'Wiadomości i rozmowy zostały – widać w nich numery.');
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
render('contacts', $data + ['title' => 'Kontakty', 'nav' => 'contacts', 'count' => (int) Db::val('SELECT COUNT(*) FROM contacts'),
    'noGroup' => (int) Db::val('SELECT COUNT(*) FROM contacts c WHERE NOT EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = c.id)')]);
