<?php
// Grupy (rozdz. 2.8) – usunięcie grupy nie usuwa kontaktów
if (is_post()) {
    $name = mb_substr(trim(input('name')), 0, 190); // ta sama długość przy sprawdzaniu duplikatu i zapisie
    $id = (int) input('id');
    if (isset($_POST['delete']) && $id > 0) {
        Db::exec('DELETE FROM `groups` WHERE id = ?', [$id]);
        flash('ok', t('groups.deleted'), t('groups.deleted_text'));
    } elseif ($name === '') {
        flash('err', t('groups.name_required'));
    } elseif (($other = Db::val('SELECT id FROM `groups` WHERE name = ?', [$name])) !== null && (int) $other !== $id) {
        flash('err', t('groups.exists', ['name' => $name]));
    } elseif ($id > 0) {
        Db::update('`groups`', ['name' => mb_substr($name, 0, 190)], 'id = ?', [$id]);
        flash('ok', t('groups.renamed'));
    } else {
        Db::insert('`groups`', ['name' => mb_substr($name, 0, 190), 'created_at' => now_db()]);
        flash('ok', t('groups.added', ['name' => $name]));
    }
    redirect(url('groups'));
}
render('groups', ['title' => t('nav.groups'), 'nav' => 'groups', 'groups' => Contacts::groups(), 'edit' => (int) input('edit')]);
