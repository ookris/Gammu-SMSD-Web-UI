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
