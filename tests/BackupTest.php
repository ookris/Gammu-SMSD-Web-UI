<?php
test('kopia danych: eksport → import na pustej bazie odtwarza dane', function () {
    reset_db();
    $now = now_db();
    $g = Db::insert('groups', ['name' => 'Serwis', 'created_at' => $now]);
    $c = Db::insert('contacts', ['name' => 'Jan Kowalski', 'phone' => '48601234567', 'note' => 'notatka', 'created_at' => $now, 'updated_at' => $now]);
    Db::insert('contact_group_members', ['contact_id' => $c, 'group_id' => $g]);
    Templates::save(null, 'Powitanie', 'Cześć {imie}');
    Blocklist::add('48600111222', 'spam');
    Settings::set('throttle_per_min', 7);
    Settings::set('validity_default', 360);
    Settings::set('ussd_codes', [['name' => 'Saldo', 'code' => '*101#']]);
    Db::insert('messages', ['direction' => 'in', 'phone' => '48601234567', 'body' => 'tajne', 'status' => 'received', 'created_at' => $now, 'updated_at' => $now]);

    $json = Backup::export();
    assert_true(!str_contains($json, 'tajne'), 'wiadomości nie trafiają do kopii');
    reset_db();
    $n = Backup::import($json);
    assert_same(['contacts' => 1, 'templates' => 1, 'blocked' => 1, 'skipped' => 0], array_intersect_key($n, array_flip(['contacts', 'templates', 'blocked', 'skipped'])));
    assert_same(['Jan Kowalski', 'notatka'], array_values(Db::row('SELECT name, note FROM contacts WHERE phone = ?', ['48601234567'])));
    assert_same(['Serwis'], Db::col('SELECT g.name FROM contact_group_members m JOIN `groups` g ON g.id = m.group_id'));
    assert_same(['Cześć {imie}'], Db::col('SELECT body FROM templates WHERE name = ?', ['Powitanie']));
    assert_same(true, Blocklist::isBlocked('48600111222'));
    assert_same(['7', '360'], [Settings::get('throttle_per_min'), Settings::get('validity_default')]);
    assert_same('Saldo', Settings::json('ussd_codes')[0]['name']);
    assert_same(0, (int) Db::val('SELECT COUNT(*) FROM messages'));
    // ponowny import niczego nie dubluje
    Backup::import($json);
    assert_same([1, 1, 1, 1], [(int) Db::val('SELECT COUNT(*) FROM contacts'), (int) Db::val('SELECT COUNT(*) FROM `groups`'),
        (int) Db::val('SELECT COUNT(*) FROM templates'), (int) Db::val('SELECT COUNT(*) FROM blocked_numbers')]);
});

test('kopia danych: zły plik, zła wersja i nieprawidłowe pozycje', function () {
    reset_db();
    foreach (['nie json', '[]', '{"app":"inna","format":1}'] as $bad) {
        $threw = false;
        try {
            Backup::import($bad);
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        assert_true($threw, "powinien odrzucić: $bad");
    }
    $threw = false;
    try {
        Backup::import('{"app":"smsgui","format":99}');
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    assert_true($threw, 'inna wersja formatu');
    $n = Backup::import(json_encode(['app' => 'smsgui', 'format' => 1,
        'contacts' => [['name' => 'Zły', 'phone' => 'abc'], ['name' => '', 'phone' => '601234567'], ['name' => 'Dobry', 'phone' => '601234567']],
        'settings' => ['throttle_per_min' => '999', 'lang' => 'xx', 'nieznane' => '1', 'cleanup_days' => '30', 'window_from' => '25:00']]));
    assert_same([1, 1, 6], [$n['contacts'], $n['settings'], $n['skipped']]);
    assert_same('30', Settings::get('cleanup_days'));
    assert_same('10', Settings::get('throttle_per_min'));
});
