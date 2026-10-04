<?php
// Wylogowanie – tylko POST (token CSRF sprawdza centralnie front controller)
if (!is_post()) {
    http_response_code(405);
    header('Allow: POST');
    echo view('error', ['code' => 405, 'title' => t('error.405'), 'message' => t('error.405_logout')]);
    exit;
}
Auth::logout();
flash('ok', t('auth.logged_out'));
redirect(url('login'));
