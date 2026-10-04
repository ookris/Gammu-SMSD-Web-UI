<?php
// Zmiana hasła i loginu (rozdz. 2.15)
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
render('password', ['title' => 'Zmiana hasła', 'nav' => 'password', 'error' => $error, 'username' => $username]);
