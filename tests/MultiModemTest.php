<?php
function modems_setup(array $ids = ['GSM1', 'GSM2']): void
{
    reset_db();
    Settings::set('service_status', 'active');
    Settings::set('service_checked_at', now_db());
    foreach ($ids as $id) {
        Db::insert('modem_status', ['modem' => $id, 'signal_pct' => 80, 'gammu_updated_at' => now_db(), 'updated_at' => now_db()]);
    }
    Status::forget();
}

test('Kilka modemów: wybór tylko znanych modemów, formularz pokazuje listę dopiero przy ≥ 2', function () {
    modems_setup(['GSM1']);
    assert_true(!Status::multiModem(), 'jeden modem');
    modems_setup();
    assert_true(Status::multiModem(), 'dwa modemy');
    assert_same('GSM2', Status::validModem('GSM2'));
    foreach (['GSM3', '', null, ['GSM1']] as $bad) {
        assert_same(null, Status::validModem($bad));
    }
});

test('Kilka modemów: wysyłka przez wybrany modem ustawia SenderID, bez wyboru – dowolny', function () {
    modems_setup();
    $_POST = ['numbers' => '601234567', 'text' => 'Test', 'modem' => 'GSM2'];
    $in = Compose::input();
    assert_same('GSM2', $in['modem']);
    [, $first] = Compose::send($in, Compose::plan($in));
    $m = Db::row('SELECT modem_requested, gammu_id FROM messages WHERE id = ?', [$first]);
    assert_same(['GSM2', 'GSM2'], [$m['modem_requested'], Db::val('SELECT SenderID FROM {g}.outbox WHERE ID = ?', [$m['gammu_id']])]);
    $_POST = ['numbers' => '601234568', 'text' => 'Test', 'modem' => 'nieznany'];
    $in = Compose::input();
    assert_same(null, $in['modem']);
    [, $second] = Compose::send($in, Compose::plan($in));
    assert_same(null, Db::val('SELECT SenderID FROM {g}.outbox WHERE ID = (SELECT gammu_id FROM messages WHERE id = ?)', [$second]));
    $_POST = [];
});

test('Kilka modemów: odpowiedź w rozmowie idzie modemem, który odebrał ostatnią wiadomość', function () {
    modems_setup();
    $ins = static fn (string $modem) => Db::insert('messages', ['direction' => 'in', 'phone' => '48601234567', 'body' => 'x', 'status' => 'received', 'modem' => $modem,
        'received_at' => now_db(), 'created_at' => now_db(), 'updated_at' => now_db()]);
    $ins('GSM1');
    $ins('GSM2');
    assert_same('GSM2', Threads::replyModem('48601234567'));
    assert_same(null, Threads::replyModem('48609999999'), 'nieznany numer');
    Db::update('modem_status', ['gammu_updated_at' => now_db(-3600)], "modem = 'GSM2'");
    Status::forget();
    assert_same(null, Threads::replyModem('48601234567'), 'modem niedostępny – dowolny');
    modems_setup(['GSM1']);
    $ins('GSM1');
    assert_same(null, Threads::replyModem('48601234567'), 'jeden modem – bez wskazywania');
});

test('Kilka modemów: alarm wymienia niedostępne modemy i słaby sygnał', function () {
    modems_setup();
    assert_same(null, Alerts::conditions(['modem', 'signal'])['modem']);
    Db::update('modem_status', ['gammu_updated_at' => now_db(-3600)], "modem = 'GSM2'");
    Db::update('modem_status', ['signal_pct' => 5], "modem = 'GSM1'");
    Status::forget();
    $c = Alerts::conditions(['modem', 'signal']);
    assert_same('GSM2', $c['modem']['modem']);
    assert_same(['modem' => 'GSM1', 'pct' => 5], $c['signal']);
    $health = array_column(array_filter(Health::run(), fn ($r) => $r['id'] === 'modem'), 'level');
    assert_same(['warn'], $health, 'kontrola zdrowia zgłasza tylko niedostępny modem');
});

test('Kilka modemów: API przyjmuje znany modem, odrzuca nieznany', function () {
    modems_setup();
    [$id, $plain] = (function () { [$i, $p] = ApiTokens::create('T', ''); return [$i, $p]; })();
    $r = api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'x', 'modem' => 'GSM2']);
    assert_same(true, $r['ok']);
    assert_same('GSM2', Db::val('SELECT modem_requested FROM messages WHERE id = ?', [$r['ids'][0]]));
    $bad = api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'x', 'modem' => 'GSM9']);
    assert_same(false, $bad['ok']);
});

test('Kilka modemów: konfiguracja dodatkowego modemu na bazie głównego pliku', function () {
    $base = GammuConf::path();
    $old = is_file($base) ? file_get_contents($base) : null;
    $new = $base . '-GSM2';
    @unlink($new);
    file_put_contents($base, "[gammu]\ndevice = /dev/ttyUSB0\nconnection = at\n\n[smsd]\nservice = sql\nPhoneID = GSM1\nlogfile = /var/log/gammu-smsd/smsd.log\nPIN = 1234\nDatabase = gammu\nRunOnIncomingCall = /usr/bin/php /opt/smsgui/bin/smsgui hook call --phone='GSM1'\n");
    try {
        ob_start();
        $code = Setup::modem(['phoneid' => 'GSM2', 'device' => '/dev/ttyUSB1', 'pin' => '']);
        ob_end_clean();
        assert_same(0, $code);
        $conf = GammuConf::parse((string) file_get_contents($new));
        assert_same(['/dev/ttyUSB1', 'GSM2', '/var/log/gammu-smsd/GSM2.log', 'sql', null], [$conf->get('gammu', 'device'), $conf->get('smsd', 'phoneid'),
            $conf->get('smsd', 'logfile'), $conf->get('smsd', 'service'), $conf->get('smsd', 'pin')]);
        assert_true(str_contains((string) $conf->get('smsd', 'runonincomingcall'), "--phone='GSM2'"), 'hook połączeń z PhoneID nowego modemu');
        assert_same('0660', substr(sprintf('%o', fileperms($new)), -4));
        $before = file_get_contents($new);
        ob_start();
        $again = Setup::modem(['phoneid' => 'GSM2', 'device' => '/dev/ttyUSB7']);
        ob_end_clean();
        assert_same([2, $before], [$again, file_get_contents($new)], 'ponowne uruchomienie nie nadpisuje konfiguracji (kod 2)');
        @unlink($new);
        ob_start();
        Setup::modem(['phoneid' => 'GSM2', 'device' => '/dev/ttyUSB1']);
        ob_end_clean();
        assert_same(null, GammuConf::parse((string) file_get_contents($new))->get('smsd', 'pin'), 'PIN głównej karty nie jest kopiowany bez --pin');
        ob_start();
        $same = Setup::modem(['phoneid' => 'GSM1', 'device' => '/dev/ttyUSB1']);
        $bad = Setup::modem(['phoneid' => 'zły id!', 'device' => '/dev/ttyUSB1']);
        ob_end_clean();
        assert_same([1, 1], [$same, $bad], 'PhoneID głównego modemu i niepoprawny PhoneID są odrzucane');
    } finally {
        @unlink($new);
        $old === null ? @unlink($base) : file_put_contents($base, $old);
    }
});

test('Kilka modemów: usunięcie nieaktualnego modemu z listy przestaje zgłaszać jego niedostępność', function () {
    modems_setup();
    Db::update('modem_status', ['gammu_updated_at' => now_db(-86400)], "modem = 'GSM2'");
    Status::forget();
    assert_same('warn', Status::indicator()['level']);
    Db::exec("DELETE FROM modem_status WHERE modem = 'GSM2'");
    Status::forget();
    assert_same('ok', Status::indicator()['level']);
});

test('Kilka modemów: ponowne add-modem nie twierdzi, że usunął PIN, skoro niczego nie zmienia', function () {
    $base = GammuConf::path();
    $old = is_file($base) ? file_get_contents($base) : null;
    $new = $base . '-GSM3';
    file_put_contents($base, "[gammu]\ndevice = /dev/ttyUSB0\n\n[smsd]\nPhoneID = GSM1\nPIN = 1234\n");
    file_put_contents($new, "[smsd]\nPIN = 1234\n");
    try {
        ob_start();
        $code = Setup::modem(['phoneid' => 'GSM3', 'device' => '/dev/ttyUSB2']);
        $out = (string) ob_get_clean();
        assert_same(2, $code);
        assert_true(!str_contains($out, 'PIN'), 'bez komunikatu o PIN-ie: ' . $out);
    } finally {
        @unlink($new);
        $old === null ? @unlink($base) : file_put_contents($base, $old);
    }
});

test('Kilka modemów: „Usuń z listy” nie wraca przy synchronizacji, dopóki modem się nie zgłosi', function () {
    modems_setup(['GSM1']);
    $old = now_db(-86400);
    Db::insert(Db::g() . '.phones', ['ID' => 'GSM9', 'IMEI' => '1', 'IMSI' => '', 'Client' => 'x', 'UpdatedInDB' => $old, 'InsertIntoDB' => $old, 'TimeOut' => $old]);
    Sync::run(true);
    assert_true(in_array('GSM9', Status::modemIds(), true), 'stary wiersz phones trafia do listy');
    Settings::set('modems_forgotten', ['GSM9' => (string) Db::val("SELECT gammu_updated_at FROM modem_status WHERE modem = 'GSM9'")]);
    Db::exec("DELETE FROM modem_status WHERE modem = 'GSM9'");
    Sync::run(true);
    Status::forget();
    assert_true(!in_array('GSM9', Status::modemIds(), true), 'nie wraca');
    Db::exec('UPDATE ' . Db::g() . ".phones SET UpdatedInDB = ? WHERE ID = 'GSM9'", [now_db()]);
    Sync::run(true);
    Status::forget();
    assert_true(in_array('GSM9', Status::modemIds(), true), 'wraca po ponownym zgłoszeniu');
    assert_same([], Settings::json('modems_forgotten'));
});
