<?php
test('logowanie poprawnym hasłem', function () {
    reset_db();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SESSION = [];
    Auth::setCredentials('admin', 'tajne-haslo-123');
    assert_same(null, @Auth::attempt('admin', 'tajne-haslo-123', false));
    assert_true((int) $_SESSION['uid'] > 0);
});

test('blokada po 5 nieudanych próbach z jednego IP', function () {
    reset_db();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.6';
    $_SESSION = [];
    Auth::setCredentials('admin', 'tajne-haslo-123');
    for ($i = 1; $i <= 5; $i++) {
        $err = @Auth::attempt('admin', 'zle', false);
        assert_true($err !== null);
    }
    assert_contains('Zbyt wiele', (string) @Auth::attempt('admin', 'tajne-haslo-123', false));
    assert_true(Auth::lockedFor('10.0.0.6') > 0);
    assert_same(0, Auth::lockedFor('10.0.0.7'));
});

test('login bez rozróżniania wielkości liter', function () {
    reset_db();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.8';
    $_SESSION = [];
    Auth::setCredentials('Admin', 'tajne-haslo-123');
    assert_same(null, @Auth::attempt('admin', 'tajne-haslo-123', false));
});
