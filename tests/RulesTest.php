<?php
function rules_setup(): array
{
    reset_db();
    FakeChannel::$sent = [];
    FakeChannel::$fail = null;
    Channels::$factory = static fn (array $row) => $row['name'] === 'fake' ? new FakeChannel() : null;
    $ch = Db::insert('channels', ['type' => 'webhook', 'name' => 'fake', 'config' => '{}', 'enabled' => 1, 'created_at' => now_db()]);
    return [$ch, Db::insert('contacts', ['name' => 'Jan', 'phone' => '48601111111', 'created_at' => now_db(), 'updated_at' => now_db()])];
}

function rules_msg(string $phone, string $body): int
{
    return Db::insert('messages', ['direction' => 'in', 'phone' => $phone, 'body' => $body, 'status' => 'received', 'is_read' => 0,
        'received_at' => now_db(), 'created_at' => now_db(), 'updated_at' => now_db()]);
}

test('Reguły: zapis i walidacja warunków', function () {
    [$ch] = rules_setup();
    [$id, $err] = Rules::save(null, ['name' => '', 'event' => 'sms', 'cond_type' => 'text', 'text' => ' faktura ', 'channel_id' => $ch]);
    assert_same(null, $err);
    $r = Rules::find($id);
    assert_same(['faktura', 'sms', 1], [$r['cond_value'], $r['event'], (int) $r['enabled']]);
    assert_true($r['name'] !== '', 'nazwa domyślna z opisu warunku');
    foreach ([
        ['event' => 'call', 'cond_type' => 'text', 'text' => 'x'],
        ['event' => 'sms', 'cond_type' => 'number', 'number' => '!!'],
        ['event' => 'sms', 'cond_type' => 'group', 'group' => 999],
        ['event' => 'sms', 'cond_type' => 'text', 'text' => '  '],
    ] as $bad) {
        assert_true(Rules::save(null, $bad + ['name' => '', 'channel_id' => $ch])[1] !== null, 'odrzucona: ' . json_encode($bad));
    }
    assert_true(Rules::save(null, ['name' => '', 'event' => 'sms', 'cond_type' => 'all', 'channel_id' => 999])[1] !== null, 'zły kanał');
    Channels::$factory = null;
});

test('Reguły: dopasowanie – wszystkie, numer, grupa, treść (wielkość liter i polskie znaki)', function () {
    [$ch, $contact] = rules_setup();
    $g = Db::insert('groups', ['name' => 'Serwis', 'created_at' => now_db()]);
    Db::insert('contact_group_members', ['contact_id' => $contact, 'group_id' => $g]);
    $sms = static fn (string $phone, string $body) => ['event' => 'sms', 'phone' => $phone, 'body' => $body];
    $rule = static fn (string $type, string $value, string $event = 'sms') => ['event' => $event, 'cond_type' => $type, 'cond_value' => $value];
    assert_true(Rules::matches($rule('all', ''), $sms('48600000000', 'x')), 'all');
    assert_true(!Rules::matches($rule('all', '', 'call'), $sms('48600000000', 'x')), 'inne zdarzenie');
    assert_true(Rules::matches($rule('number', '48601111111'), $sms('48601111111', 'x')), 'numer');
    assert_true(!Rules::matches($rule('number', '48601111111'), $sms('48602222222', 'x')), 'inny numer');
    assert_true(Rules::matches($rule('number', 'ORANGE'), $sms('orange', 'x')), 'nadawca alfanumeryczny bez względu na wielkość');
    assert_true(Rules::matches($rule('group', (string) $g), $sms('48601111111', 'x')), 'grupa');
    assert_true(!Rules::matches($rule('group', (string) $g), $sms('48602222222', 'x')), 'spoza grupy');
    assert_true(Rules::matches($rule('text', 'zażółć'), $sms('1', 'To jest ZAŻÓŁĆ gęślą')), 'treść, polskie litery');
    assert_true(!Rules::matches($rule('text', 'faktura'), $sms('1', 'rachunek')), 'brak tekstu');
    assert_true(!Rules::matches($rule('text', 'x', 'call'), ['event' => 'call', 'phone' => '1', 'body' => 'x']), 'treść nie dotyczy połączeń');
    Channels::$factory = null;
});

test('Reguły: przekazywanie nowych SMS i połączeń, kursor, blokada i brak historii', function () {
    [$ch] = rules_setup();
    $old = rules_msg('48600000001', 'stara wiadomość');
    Settings::set('rules_msg_cursor', $old);
    Rules::save(null, ['name' => 'Wszystko', 'event' => 'sms', 'cond_type' => 'all', 'channel_id' => $ch]);
    [$faktury] = Rules::save(null, ['name' => 'Faktury', 'event' => 'sms', 'cond_type' => 'text', 'text' => 'faktura', 'channel_id' => $ch]);
    Rules::save(null, ['name' => 'Połączenia', 'event' => 'call', 'cond_type' => 'all', 'channel_id' => $ch]);

    assert_same(0, Rules::process(), 'historia nie jest przekazywana');
    rules_msg('48601111111', 'Nowa faktura nr 5');
    rules_msg('48602222222', 'Cześć');
    Blocklist::add('48603333333');
    rules_msg('48603333333', 'spam');
    Db::insert('calls', ['phone' => '48604444444', 'raw_number' => '604444444', 'modem' => 'GSM1', 'received_at' => now_db()]);
    Db::insert('calls', ['phone' => '', 'raw_number' => '', 'modem' => 'GSM1', 'received_at' => now_db()]);

    assert_same(5, Rules::process(), '2 SMS × reguła „wszystko” + faktura + 2 połączenia');
    assert_same(0, Rules::process(), 'kursor – bez powtórzeń');
    assert_same(5, (int) Db::val('SELECT COUNT(*) FROM deliveries'));
    assert_same(1, (int) Rules::find($faktury)['hits']);

    Sync::run(true);
    $subjects = array_column(FakeChannel::$sent, 'subject');
    assert_true(in_array('SMS od Jan', $subjects, true), 'nazwa kontaktu w temacie: ' . implode(' | ', $subjects));
    assert_true(in_array('Połączenie od 48604444444', $subjects, true) || count(array_filter($subjects, fn ($s) => str_starts_with($s, 'Połączenie od'))) === 2, 'połączenia');
    $sms = array_values(array_filter(FakeChannel::$sent, fn ($m) => ($m['data']['type'] ?? '') === 'sms' && $m['data']['name'] === 'Jan'))[0];
    assert_same('+48601111111', $sms['data']['from']);
    assert_true(str_starts_with($sms['text'], 'Nowa faktura nr 5'), 'treść');
    Channels::$factory = null;
});

test('Reguły: wyłączona reguła i wyłączony kanał nie kolejkują; SMS do nadawcy nie tworzy pętli', function () {
    [$ch] = rules_setup();
    Settings::set('rules_msg_cursor', 0);
    [$id] = Rules::save(null, ['name' => 'R', 'event' => 'sms', 'cond_type' => 'all', 'channel_id' => $ch]);
    Rules::setEnabled($id, false);
    rules_msg('48601111111', 'a');
    assert_same(0, Rules::process());
    Rules::setEnabled($id, true);
    Channels::setEnabled($ch, false);
    rules_msg('48601111111', 'b');
    assert_same(0, Rules::process());

    [$sms] = Channels::save(null, 'sms', ['name' => 'Admin', 'number' => '601111111']);
    Rules::save($id, ['name' => 'R', 'event' => 'sms', 'cond_type' => 'all', 'channel_id' => $sms]);
    rules_msg('48601111111', 'c');
    assert_same(0, Rules::process(), 'nadawca = odbiorca kanału SMS');
    rules_msg('48605555555', 'd');
    assert_same(1, Rules::process());
    Channels::$factory = null;
});

test('Reguły: „Wyślij test” wysyła przykładowe zdarzenie do kanału', function () {
    [$ch] = rules_setup();
    [$id] = Rules::save(null, ['name' => 'R', 'event' => 'sms', 'cond_type' => 'all', 'channel_id' => $ch]);
    assert_same(null, Rules::test($id));
    assert_same('sms', FakeChannel::$sent[0]['data']['type']);
    FakeChannel::$fail = 'nie działa';
    assert_same('nie działa', Rules::test($id));
    assert_same('nie działa', Rules::find($id)['last_error']);
    Channels::$factory = null;
});

test('Autoodpowiedzi: walidacja zapisu', function () {
    rules_setup();
    $ok = ['name' => '', 'event' => 'sms', 'cond_type' => 'equals', 'text' => 'info', 'reply_text' => 'Godziny: 8-16'];
    [$id, $err] = Rules::save(null, $ok, 'autoreply');
    assert_same(null, $err);
    $r = Rules::find($id);
    assert_same(['autoreply', 'equals', 'info', null], [$r['kind'], $r['cond_type'], $r['cond_value'], $r['channel_id']]);
    assert_true(Rules::save(null, ['reply_text' => ' '] + $ok, 'autoreply')[1] !== null, 'pusta odpowiedź');
    assert_true(Rules::save(null, ['reply_text' => str_repeat('x', 1700)] + $ok, 'autoreply')[1] !== null, 'za długa');
    assert_true(Rules::save(null, ['hours_mode' => 'outside', 'hours_from' => '08:00', 'hours_to' => '08:00'] + $ok, 'autoreply')[1] !== null, 'puste okno');
    assert_true(Rules::save(null, ['hours_mode' => 'outside', 'hours_from' => 'x', 'hours_to' => '16:00'] + $ok, 'autoreply')[1] !== null, 'zła godzina');
    assert_true(Rules::save(null, ['event' => 'call', 'cond_type' => 'starts'] + $ok, 'autoreply')[1] !== null, 'treść przy połączeniu');
    [$h] = Rules::save(null, ['hours_mode' => 'outside', 'hours_from' => '08:00', 'hours_to' => '16:00', 'workdays' => true] + $ok, 'autoreply');
    $hr = Rules::find($h);
    assert_same(['outside', '08:00:00', '16:00:00', 1], [$hr['hours_mode'], $hr['hours_from'], $hr['hours_to'], (int) $hr['workdays_only']]);
    assert_same(0, count(Rules::all('forward')), 'osobne listy');
    assert_same(2, count(Rules::all('autoreply')));
    Channels::$factory = null;
});

test('Reguły: dopasowanie „równa się / zaczyna się” i godziny (poza, w, przez północ, dni robocze)', function () {
    rules_setup();
    $rule = static fn (string $type, string $v) => ['event' => 'sms', 'cond_type' => $type, 'cond_value' => $v, 'hours_mode' => 'any'];
    $sms = static fn (string $body) => ['event' => 'sms', 'phone' => '48601111111', 'body' => $body, 'at' => now_db()];
    assert_true(Rules::matches($rule('equals', 'Info'), $sms('  INFO ')), 'równa się bez względu na wielkość i spacje');
    assert_true(!Rules::matches($rule('equals', 'info'), $sms('info proszę')), 'nie równa się');
    assert_true(Rules::matches($rule('starts', 'info'), $sms('Info o ofercie')), 'zaczyna się');
    assert_true(!Rules::matches($rule('starts', 'info'), $sms('proszę o info')), 'nie zaczyna się');

    $mon = strtotime('2026-11-02 12:00:00'); // poniedziałek
    $sat = strtotime('2026-11-07 12:00:00');
    $h = static fn (string $mode, string $from, string $to, int $work = 0) => ['hours_mode' => $mode, 'hours_from' => $from, 'hours_to' => $to, 'workdays_only' => $work];
    assert_true(Rules::inHours($h('inside', '08:00:00', '16:00:00'), $mon), 'w godzinach');
    assert_true(!Rules::inHours($h('outside', '08:00:00', '16:00:00'), $mon), 'nie poza godzinami');
    assert_true(Rules::inHours($h('outside', '08:00:00', '12:00:00'), $mon), 'granica „do” wyłączona');
    assert_true(Rules::inHours($h('outside', '08:00:00', '16:00:00', 1), $sat), 'weekend = poza godzinami');
    assert_true(!Rules::inHours($h('inside', '08:00:00', '16:00:00', 1), $sat), 'weekend nie jest „w godzinach”');
    assert_true(Rules::inHours($h('inside', '08:00:00', '16:00:00', 0), $sat), 'bez dni roboczych sobota też');
    $night = $h('inside', '22:00:00', '06:00:00');
    assert_true(Rules::inHours($night, strtotime('2026-11-02 23:30:00')) && Rules::inHours($night, strtotime('2026-11-02 05:59:00')), 'przez północ');
    assert_true(!Rules::inHours($night, $mon), 'południe poza oknem nocnym');
    assert_true(Rules::inHours(['hours_mode' => 'any'], $mon), 'bez warunku');
    Channels::$factory = null;
});

test('Autoodpowiedzi: wysyłka SMS, personalizacja, jedna odpowiedź na zdarzenie i pauza godzinna', function () {
    [$ch, $contact] = rules_setup();
    Rules::save(null, ['name' => 'Info', 'event' => 'sms', 'cond_type' => 'equals', 'text' => 'info', 'reply_text' => 'Cześć {imie}, godziny: 8-16'], 'autoreply');
    Rules::save(null, ['name' => 'Reszta', 'event' => 'sms', 'cond_type' => 'all', 'reply_text' => 'Odezwiemy się'], 'autoreply');
    Rules::save(null, ['name' => 'Połączenie', 'event' => 'call', 'cond_type' => 'all', 'reply_text' => 'Przepraszamy, oddzwonimy'], 'autoreply');

    rules_msg('48601111111', 'INFO');
    assert_same(1, Rules::process());
    $o = Db::row("SELECT phone, body, source, status, report FROM messages WHERE direction = 'out'");
    assert_same(['48601111111', 'Cześć Jan, godziny: 8-16', 'autoreply', 'queued', 0], [$o['phone'], $o['body'], $o['source'], $o['status'], (int) $o['report']]);

    rules_msg('48601111111', 'info');
    assert_same(0, Rules::process(), 'pauza godzinna dla tego numeru');
    rules_msg('48602222222', 'info');
    rules_msg('48603333333', 'cokolwiek');
    assert_same(2, Rules::process(), 'jedna odpowiedź na wiadomość (pasuje „info” albo „reszta”)');
    assert_same('Odezwiemy się', Db::val("SELECT body FROM messages WHERE direction = 'out' AND phone = '48603333333'"));
    assert_same('Cześć , godziny: 8-16', Db::val("SELECT body FROM messages WHERE direction = 'out' AND phone = '48602222222'"));

    Db::insert('calls', ['phone' => '48604444444', 'raw_number' => '', 'modem' => 'GSM1', 'received_at' => now_db()]);
    assert_same(1, Rules::process());
    assert_same('Przepraszamy, oddzwonimy', Db::val("SELECT body FROM messages WHERE direction = 'out' AND phone = '48604444444'"));
    Channels::$factory = null;
});

test('Autoodpowiedzi: bez odpowiedzi nadawcom alfanumerycznym, skróconym, ukrytym i zablokowanym', function () {
    rules_setup();
    Rules::save(null, ['name' => 'Wszystko', 'event' => 'sms', 'cond_type' => 'all', 'reply_text' => 'x'], 'autoreply');
    Rules::save(null, ['name' => 'Połączenia', 'event' => 'call', 'cond_type' => 'all', 'reply_text' => 'x'], 'autoreply');
    rules_msg('ORANGE', 'promocja');
    rules_msg('8080', 'kod');
    Blocklist::add('48605555555');
    rules_msg('48605555555', 'zablokowany');
    Db::insert('calls', ['phone' => '', 'raw_number' => '', 'modem' => 'GSM1', 'received_at' => now_db()]);
    assert_same(0, Rules::process());
    assert_same(0, (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out'"));
    Channels::$factory = null;
});

test('Autoodpowiedzi: dwie bramki odpowiadające sobie nie wpadają w pętlę', function () {
    rules_setup();
    Rules::save(null, ['name' => 'Wszystko', 'event' => 'sms', 'cond_type' => 'all', 'reply_text' => 'Dziękujemy'], 'autoreply');
    rules_msg('48606666666', 'Dzień dobry');
    assert_same(1, Rules::process(), 'pierwsza odpowiedź');
    for ($i = 0; $i < 5; $i++) {
        rules_msg('48606666666', 'Dziękujemy'); // druga bramka odpowiada automatem
        assert_same(0, Rules::process(), "odpowiedź na automat #$i zablokowana");
    }
    assert_same(1, (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND source = 'autoreply'"));
    Channels::$factory = null;
});

test('Autoodpowiedzi: odpowiedź idzie modemem, który odebrał wiadomość', function () {
    modems_setup();
    Channels::$factory = null;
    Rules::save(null, ['name' => 'R', 'event' => 'sms', 'cond_type' => 'all', 'reply_text' => 'ok'], 'autoreply');
    Db::insert('messages', ['direction' => 'in', 'phone' => '48601234567', 'body' => 'x', 'status' => 'received', 'modem' => 'GSM2', 'is_read' => 0,
        'received_at' => now_db(), 'created_at' => now_db(), 'updated_at' => now_db()]);
    assert_same(1, Rules::process());
    assert_same('GSM2', Db::val("SELECT SenderID FROM {g}.outbox WHERE ID = (SELECT gammu_id FROM messages WHERE direction = 'out')"));
});

test('Przekazywanie: webhook dostaje surową treść SMS w polu body', function () {
    rules_setup();
    $m = Rules::format(['event' => 'sms', 'phone' => '48601111111', 'body' => 'Kod 123456', 'modem' => 'GSM1', 'at' => now_db()]);
    assert_same('Kod 123456', $m['data']['body']);
    assert_true(str_starts_with($m['text'], 'Kod 123456') && $m['text'] !== 'Kod 123456', 'tekst ze stopką');
    assert_true(!isset(Rules::format(['event' => 'call', 'phone' => '48601111111', 'body' => '', 'modem' => '', 'at' => now_db()])['data']['body']), 'połączenie bez body');
    Channels::$factory = null;
});
