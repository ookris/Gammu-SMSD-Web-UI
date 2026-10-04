<?php
test('migracje na pustej bazie tworzą tabele panelu', function () {
    reset_db();
    assert_same(1, Db::schemaVersion());
    $tables = Db::col('SHOW TABLES');
    foreach (['users', 'contacts', 'groups', 'contact_group_members', 'messages', 'calls', 'ussd_requests', 'blocked_numbers',
        'modem_status', 'templates', 'settings', 'login_attempts', 'schema_version'] as $t) {
        assert_true(in_array($t, $tables, true), "brak tabeli $t");
    }
});

test('ponowne uruchomienie migracji nic nie psuje', function () {
    Db::set(Db::connect());
    Db::migrate();
    Db::migrate();
    assert_same(1, (int) Db::val('SELECT COUNT(*) FROM schema_version'));
});

test('transakcja obejmuje bazę panelu i bazę Gammu', function () {
    reset_db();
    try {
        Db::tx(function () {
            Db::insert('templates', ['name' => 'x', 'body' => 'y', 'created_at' => now_db(), 'updated_at' => now_db()]);
            Db::insert(Db::g() . '.outbox', ['DestinationNumber' => '+48601234567', 'TextDecoded' => 'x', 'CreatorID' => 'smsgui']);
            throw new RuntimeException('wycofaj');
        });
    } catch (RuntimeException) {
    }
    assert_same(0, (int) Db::val('SELECT COUNT(*) FROM templates'));
    assert_same(0, (int) Db::val('SELECT COUNT(*) FROM {g}.outbox'));
});

test('emoji zapisują się bez zamiany na ?', function () {
    reset_db();
    Db::insert('templates', ['name' => 'e', 'body' => 'Dzięki 👍', 'created_at' => now_db(), 'updated_at' => now_db()]);
    assert_same('Dzięki 👍', Db::val('SELECT body FROM templates'));
});

test('licznik UDH zawija się po 255', function () {
    reset_db();
    Settings::set('udh_ref', '254');
    assert_same(255, Settings::nextUdhRef());
    assert_same(0, Settings::nextUdhRef());
});
