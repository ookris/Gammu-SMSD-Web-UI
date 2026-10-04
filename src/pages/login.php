<?php
// Logowanie (rozdz. 2.1)
$error = null;
$username = input('username');
if (Auth::user() !== null) {
    redirect('./');
}
if (is_post()) {
    if (!csrf_valid()) {
        $error = t('auth.csrf');
    } else {
        $error = Auth::attempt($username, (string) ($_POST['password'] ?? ''), isset($_POST['remember']));
        if ($error === null) {
            $next = input('next');
            redirect(preg_match('~^\?p=[a-z0-9-]+~', $next) ? $next : './');
        }
    }
} elseif (!empty($_SESSION['expired'])) {
    unset($_SESSION['expired']);
    $error = t('auth.expired');
} elseif (($locked = Auth::lockedFor(request_ip())) > 0) {
    $error = t('auth.locked', ['min' => (int) ceil($locked / 60)]);
}
$flashes = take_flashes();
echo view('login', ['error' => $error, 'username' => $username, 'next' => input('next'), 'flashes' => $flashes]);
