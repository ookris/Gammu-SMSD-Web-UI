<?php
function gammu_sent(int $id, int $seq, string $status, array $extra = []): void
{
    Db::insert(Db::g() . '.sentitems', $extra + ['ID' => $id, 'SequencePosition' => $seq, 'Status' => $status, 'DestinationNumber' => '+48601234567',
        'TextDecoded' => 'część ' . $seq, 'Text' => '', 'UDH' => '', 'SenderID' => 'GSM1', 'CreatorID' => 'smsgui', 'SendingDateTime' => now_db()]);
}

function gammu_inbox(string $from, string $text, string $udh = '', array $extra = []): int
{
    return Db::insert(Db::g() . '.inbox', $extra + ['SenderNumber' => $from, 'TextDecoded' => $text, 'Text' => '', 'UDH' => $udh,
        'RecipientID' => 'GSM1', 'ReceivingDateTime' => now_db(), 'UpdatedInDB' => now_db(-10)]);
}

test('zapis wiadomości jednoczęściowej', function () {
    reset_db();
    $id = Outbox::create(['phone' => '48601234567', 'body' => 'Test', 'report' => true]);
    $m = Db::row('SELECT * FROM messages WHERE id = ?', [$id]);
    $o = Db::row('SELECT * FROM {g}.outbox WHERE ID = ?', [$m['gammu_id']]);
    assert_same(['queued', 1, 'GSM'], [$m['status'], (int) $m['parts'], $m['encoding']]);
    assert_same(['+48601234567', 'Test', 'Default_No_Compression', 'false', 'yes', 'smsgui', 10], [$o['DestinationNumber'], $o['TextDecoded'],
        $o['Coding'], $o['MultiPart'], $o['DeliveryReport'], $o['CreatorID'], (int) $o['Priority']]);
    assert_same(0, (int) Db::val('SELECT COUNT(*) FROM {g}.outbox_multipart'));
});

test('500 znaków z polskimi literami → 1 wiersz outbox + 7 outbox_multipart z UDH', function () {
    reset_db();
    $text = mb_substr(str_repeat('Zażółć gęślą jaźń. ', 30), 0, 500);
    $id = Outbox::create(['phone' => '48601234567', 'body' => $text]);
    $gid = (int) Db::val('SELECT gammu_id FROM messages WHERE id = ?', [$id]);
    $o = Db::row('SELECT * FROM {g}.outbox WHERE ID = ?', [$gid]);
    $mp = Db::all('SELECT * FROM {g}.outbox_multipart WHERE ID = ? ORDER BY SequencePosition', [$gid]);
    assert_same(['true', 'Unicode_No_Compression'], [$o['MultiPart'], $o['Coding']]);
    assert_same(7, count($mp));
    assert_same([2, 8], [(int) $mp[0]['SequencePosition'], (int) $mp[6]['SequencePosition']]);
    $ref = sprintf('%02X', (int) Db::val('SELECT udh_ref FROM messages WHERE id = ?', [$id]));
    assert_same('050003' . $ref . '0801', $o['UDH']);
    assert_same('050003' . $ref . '0808', $mp[6]['UDH']);
    assert_same($text, $o['TextDecoded'] . implode('', array_column($mp, 'TextDecoded')));
});

test('„Mój kot” jako Unicode_No_Compression', function () {
    reset_db();
    $id = Outbox::create(['phone' => '48601234567', 'body' => 'Mój kot']);
    assert_same('Unicode_No_Compression', Db::val('SELECT o.Coding FROM {g}.outbox o JOIN messages m ON m.gammu_id = o.ID WHERE m.id = ?', [$id]));
});

test('łączenie statusów części (tabela 3.5)', function () {
    $st = fn (array $parts) => GammuDb::state(null, array_map(fn ($s) => ['Status' => $s, 'StatusError' => 70, 'StatusCode' => 500,
        'SenderID' => 'GSM1', 'SendingDateTime' => '2026-10-04 10:00:00', 'DeliveryDateTime' => '2026-10-04 10:00:05'], $parts), 1)['status'];
    assert_same('sent', $st(['SendingOK', 'SendingOK']));
    assert_same('sent', $st(['DeliveryOK', 'SendingOK']));
    assert_same('delivered', $st(['DeliveryOK', 'DeliveryOK']));
    assert_same('undelivered', $st(['DeliveryOK', 'DeliveryFailed']));
    assert_same('failed', $st(['SendingOK', 'SendingError']));
    assert_same('failed', $st([]));
    $q = GammuDb::state(['Retries' => 1, 'StatusCode' => 500, 'SendingTimeOut' => now_db(300), 'waiting' => 0], [], 2);
    assert_same('queued', $q['status']);
    assert_contains('próba 2 z 3', tr($q['error']));
    assert_contains('kod 500: nieznany błąd', tr($q['error']));
    assert_same('scheduled', GammuDb::state(['Retries' => 0, 'StatusCode' => -1, 'SendingTimeOut' => null, 'waiting' => 1], [], 1)['status']);
});

test('anulowanie: wolny wiersz usunięty, zablokowany przez Gammu – nie', function () {
    reset_db();
    $a = Outbox::create(['phone' => '48601234567', 'body' => 'A']);
    $b = Outbox::create(['phone' => '48601234567', 'body' => 'B']);
    Db::exec('UPDATE {g}.outbox SET SendingTimeOut = NOW() + INTERVAL 60 SECOND WHERE ID = (SELECT gammu_id FROM messages WHERE id = ?)', [$b]);
    assert_same('cancelled', Outbox::cancel($a));
    assert_same('busy', Outbox::cancel($b));
    assert_same(['cancelled', 'queued'], [Db::val('SELECT status FROM messages WHERE id = ?', [$a]), Db::val('SELECT status FROM messages WHERE id = ?', [$b])]);
});

test('synchronizacja: wysłana → doręczona, ponowienie z nowym gammu_id', function () {
    reset_db();
    $id = Outbox::create(['phone' => '48601234567', 'body' => 'X', 'report' => true]);
    $gid = (int) Db::val('SELECT gammu_id FROM messages WHERE id = ?', [$id]);
    Db::exec('DELETE FROM {g}.outbox WHERE ID = ?', [$gid]);
    gammu_sent($gid, 1, 'SendingOK');
    Sync::run();
    assert_same('sent', Db::val('SELECT status FROM messages WHERE id = ?', [$id]));
    Db::exec("UPDATE {g}.sentitems SET Status = 'DeliveryFailed', StatusError = 70 WHERE ID = ?", [$gid]);
    Sync::run();
    $m = Db::row('SELECT status, error FROM messages WHERE id = ?', [$id]);
    assert_same('undelivered', $m['status']);
    assert_same('Status 70: upłynął czas ważności – odbiorca nieosiągalny', tr($m['error']));
    assert_true(Outbox::retry($id));
    $m = Db::row('SELECT status, gammu_id FROM messages WHERE id = ?', [$id]);
    assert_same('queued', $m['status']);
    assert_true((int) $m['gammu_id'] !== $gid);
});

test('synchronizacja: wiadomość zewnętrzna (CreatorID ≠ smsgui)', function () {
    reset_db();
    gammu_sent(900, 1, 'SendingOKNoReport', ['CreatorID' => 'inject', 'TextDecoded' => 'Alarm UPS']);
    Sync::run();
    $m = Db::row("SELECT * FROM messages WHERE source = 'external'");
    assert_same(['48601234567', 'Alarm UPS', 'sent', 900], [$m['phone'], $m['body'], $m['status'], (int) $m['gammu_id']]);
    Sync::run();
    assert_same(1, (int) Db::val('SELECT COUNT(*) FROM messages'));
});

test('import inbox: części sklejone przez Gammu i niesklejone, niekompletne, flash', function () {
    reset_db();
    gammu_inbox('+48601234567', 'Całość długiej wiadomości', '0500031A0201');
    gammu_inbox('+48601234567', '', '0500031A0202');
    gammu_inbox('+48502113908', 'Pierwsza ', '0500032B0201');
    gammu_inbox('+48502113908', 'druga', '0500032B0202');
    gammu_inbox('+48515330921', 'Tylko 1 z 3', '0500033C0301');
    gammu_inbox('ORLEN', 'Kod rabatowy', '', ['Class' => 0]);
    Sync::run();
    $rows = Db::all("SELECT phone, body, parts, incomplete, flash FROM messages WHERE direction = 'in' ORDER BY id");
    assert_same(4, count($rows));
    assert_same(['48601234567', 'Całość długiej wiadomości', 2, 0], [$rows[0]['phone'], $rows[0]['body'], (int) $rows[0]['parts'], (int) $rows[0]['incomplete']]);
    assert_same('Pierwsza druga', $rows[1]['body']);
    assert_same([3, 1], [(int) $rows[2]['parts'], (int) $rows[2]['incomplete']]);
    assert_same(['ORLEN', 1], [$rows[3]['phone'], (int) $rows[3]['flash']]);
    assert_same(0, (int) Db::val("SELECT COUNT(*) FROM {g}.inbox WHERE Processed = 'false'"));
});

test('import inbox: tryb delete usuwa wiersze, świeże wiersze czekają', function () {
    reset_db();
    $cfg = app_config();
    app_config(array_replace($cfg, ['gammu_rows' => 'delete']));
    gammu_inbox('+48601234567', 'stara');
    gammu_inbox('+48601234567', 'świeża', '', ['UpdatedInDB' => now_db()]);
    Sync::run();
    app_config($cfg);
    assert_same(['stara'], Db::col("SELECT body FROM messages WHERE direction = 'in'"));
    assert_same(['świeża'], Db::col('SELECT TextDecoded FROM {g}.inbox'));
});

test('USSD: żądanie, odpowiedź, jedno na modem, przekroczenie czasu', function () {
    reset_db();
    [$id, $err] = Ussd::send('*101#', null);
    assert_same(null, $err);
    assert_same('Poprzednie żądanie USSD jeszcze czeka na odpowiedź – jedno żądanie na modem.', Ussd::send('*100#')[1]);
    $r = Ussd::get($id);
    $o = Db::row('SELECT Class, Priority, DeliveryReport FROM {g}.outbox WHERE ID = ?', [$r['gammu_id']]);
    assert_same([127, 20, 'no'], [(int) $o['Class'], (int) $o['Priority'], $o['DeliveryReport']]);
    Db::exec('DELETE FROM {g}.outbox WHERE ID = ?', [$r['gammu_id']]);
    gammu_sent((int) $r['gammu_id'], 1, 'SendingOKNoReport', ['Class' => 127, 'DestinationNumber' => '*101#']);
    gammu_inbox('', 'Twoje saldo: 12,34 zł', '', ['Class' => 127, 'Status' => 2, 'Coding' => 'Unicode_No_Compression']);
    Sync::run();
    $r = Ussd::get($id);
    assert_same(['answered', 'Twoje saldo: 12,34 zł', 2], [$r['status'], $r['response'], (int) $r['session_status']]);
    assert_same(0, (int) Db::val("SELECT COUNT(*) FROM messages"));
    [$id2] = Ussd::send('*121#');
    $gid = (int) Ussd::get($id2)['gammu_id'];
    Db::exec('DELETE FROM {g}.outbox WHERE ID = ?', [$gid]);
    gammu_sent($gid, 1, 'SendingOKNoReport', ['Class' => 127, 'SendingDateTime' => now_db(-120)]);
    Sync::run();
    Sync::run();
    assert_same('timeout', Ussd::get($id2)['status']);
});

test('hook połączeń: walidacja numeru', function () {
    foreach (['+48601234567' => true, '601234567' => true, '*100#' => true, '' => true, '$(rm -rf /)' => false, '1;ls' => false,
        '+48 601' => false] as $n => $ok) {
        assert_same($ok, Calls::validNumber((string) $n), "numer: $n");
    }
});
