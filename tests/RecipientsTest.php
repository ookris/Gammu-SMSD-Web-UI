<?php
function seed_contacts(): array
{
    reset_db();
    $g = Db::insert('`groups`', ['name' => 'Serwis', 'created_at' => now_db()]);
    $ids = [];
    foreach ([['Jan Kowalski', '601234567'], ['Anna Nowak', '502113908'], ['Piotr Wiśniewski', '698450221']] as [$n, $p]) {
        [$ids[]] = Contacts::save(null, $n, $p, '', [$g]);
    }
    return [$g, $ids];
}

test('suma odbiorców bez duplikatów, błędne numery, spoza książki', function () {
    [$g, $ids] = seed_contacts();
    $r = Recipients::resolve([$g], [$ids[0]], "601 234 567; 515330921\nbiuro, 601 23");
    assert_same(4, count($r['list']));
    assert_same(2, $r['duplicates']);
    assert_same([['biuro', 'to nie jest numer'], ['601 23', 'za krótki (wymagane 9 cyfr)']], $r['invalid']);
    assert_same(1, $r['outside']);
});

test('personalizacja {imie} i {nazwa}', function () {
    assert_same('Cześć Jan, Jan Kowalski', Recipients::personalize('Cześć {imie}, {nazwa}', 'Jan Kowalski'));
    assert_same('Cześć , ', Recipients::personalize('Cześć {imie}, {nazwa}', null));
});

test('licznik liczy najdłuższy wariant', function () {
    $l = Recipients::longest('Hej {imie}', [['name' => 'Jo'], ['name' => 'Małgorzata Nowak']], false);
    assert_same('Hej Małgorzata', $l['text']);
    assert_same(false, $l['analysis']['gsm']);
});

test('dławienie: 2 SMS/min rozkłada wysyłkę co 30 s', function () {
    $t = strtotime('2026-10-04 10:00:00');
    assert_same(['2026-10-04 10:00:00', '2026-10-04 10:00:30', '2026-10-04 10:01:00'], Recipients::schedule(3, 2, $t));
    assert_same(60, Recipients::duration(3, 2));
    assert_same(['2026-10-04 10:00:00', '2026-10-04 10:00:00'], Recipients::schedule(2, 0, $t));
});

test('okno wysyłki: start o otwarciu okna, także następnego dnia', function () {
    $w = ['08:00:00', '21:00:59'];
    assert_same('2026-10-05 08:00:00', date('Y-m-d H:i:s', Recipients::windowStart($w, strtotime('2026-10-04 22:15:00'))));
    assert_same('2026-10-04 08:00:00', date('Y-m-d H:i:s', Recipients::windowStart($w, strtotime('2026-10-04 06:00:00'))));
    assert_same('2026-10-04 12:00:00', date('Y-m-d H:i:s', Recipients::windowStart($w, strtotime('2026-10-04 12:00:00'))));
});

test('wysyłka do grupy: osobne wiersze, wspólny batch_id, priorytet 0, okno w outbox', function () {
    [$g] = seed_contacts();
    Settings::set('throttle_per_min', '2');
    Settings::set('window_enabled', '1');
    $in = Compose::defaults();
    $in['groups'] = [$g];
    $in['text'] = 'Cześć {imie}';
    $plan = Compose::plan($in);
    assert_same([], $plan['errors']);
    [$batch] = Compose::send($in, $plan);
    $rows = Db::all('SELECT m.body, m.batch_id, o.Priority, o.SendAfter, o.SendingDateTime FROM messages m JOIN {g}.outbox o ON o.ID = m.gammu_id ORDER BY m.id');
    assert_same(3, count($rows));
    assert_same(['Cześć Anna', $batch, 0, '08:00:00'], [$rows[0]['body'], $rows[0]['batch_id'], (int) $rows[0]['Priority'], $rows[0]['SendAfter']]);
    assert_same(30, (int) ts($rows[1]['SendingDateTime']) - (int) ts($rows[0]['SendingDateTime']));
});

test('CSV: separator, BOM, grupy, błędne wiersze, eksport', function () {
    seed_contacts();
    $csv = "\u{FEFF}nazwa;numer;grupy;notatka\r\nJan Kowalski;601234567;Serwis|Zarząd;klucze\r\nŁucja Żak;+48 733 019 554;;\r\nZły;abc;;\r\n;600100200;;\r\n";
    $p = Contacts::csvPreview($csv);
    assert_same([1, 1, 2], [$p['new'], $p['updated'], count($p['errors'])]);
    Contacts::csvImport($p['rows']);
    assert_same('Łucja Żak', Db::val("SELECT name FROM contacts WHERE phone = '48733019554'"));
    assert_same(2, count(Contacts::groupsOf([(int) Db::val("SELECT id FROM contacts WHERE phone = '48601234567'")])[(int) Db::val("SELECT id FROM contacts WHERE phone = '48601234567'")]));
    $p2 = Contacts::csvPreview("nazwa,numer\nEwa,664302117\n");
    assert_same(1, $p2['new']);
    $out = Contacts::csvExport();
    assert_true(str_starts_with($out, "\u{FEFF}nazwa;numer;grupy;notatka"));
    assert_contains('Jan Kowalski;+48601234567;Serwis|Zarząd;klucze', $out);
});
