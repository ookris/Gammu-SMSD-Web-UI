<?php
// Wylogowanie – link z tokenem CSRF w adresie (akcja zmienia stan)
if (csrf_valid()) {
    Auth::logout();
    flash('ok', t('auth.logged_out'));
}
redirect(url('login'));
