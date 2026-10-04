<?php
// Szablony wiadomości
$id = (int) input('id');
$error = null;
if (is_post()) {
    if (isset($_POST['delete']) && $id > 0) {
        Templates::delete($id);
        flash('ok', t('templates.deleted'));
        redirect(url('templates'));
    }
    [$saved, $error] = Templates::save($id > 0 ? $id : null, input('name'), (string) ($_POST['text'] ?? ''));
    if ($saved !== null) {
        flash('ok', t('templates.saved'));
        redirect(url('templates', ['id' => $saved]));
    }
}
$all = Templates::all();
$current = $id > 0 ? Templates::find($id) : null;
$form = is_post() ? ['name' => input('name'), 'body' => (string) ($_POST['text'] ?? '')] : ($current ?? ['name' => '', 'body' => '']);
render('templates', ['title' => t('nav.templates'), 'nav' => 'templates', 'all' => $all, 'id' => $current ? $id : 0, 'form' => $form, 'error' => $error]);
