<?php
// Zmiana hasła i loginu
$error = null;
$username = Auth::user()['username'];
if (is_post()) {
    $username = input('username');
    $error = Auth::changePassword($username, (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''), (string) ($_POST['repeat'] ?? ''));
    if ($error === null) {
        flash('ok', t('password.changed'));
        redirect(url('password'));
    }
}
render('password', ['title' => t('password.title'), 'nav' => 'password', 'error' => $error, 'username' => $username]);
