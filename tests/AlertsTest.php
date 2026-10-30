<?php
function alerts_setup(): int
{
    $ch = fake_channel();
    Settings::set('service_status', 'active');
    Settings::set('service_checked_at', now_db());
    Settings::set('worker_seen_at', now_db());
    Db::insert('modem_status', ['modem' => 'GSM1', 'signal_pct' => 80, 'gammu_updated_at' => now_db(), 'updated_at' => now_db()]);
    Alerts::saveRoutes(['service' => [$ch], 'signal' => [$ch], 'worker' => [$ch], 'queue' => [$ch], 'failures' => [$ch], 'nieznany' => [$ch]]);
    return $ch;
}

function alerts_pending(): array
{
    return Db::col("SELECT subject FROM deliveries WHERE status = 'pending' ORDER BY id");
}

test('Alerty: trasy zapisują tylko znane rodzaje i istniejące kanały', function () {
    $ch = alerts_setup();
    Alerts::saveRoutes(['service' => [$ch, 999], 'modem' => [], 'nieznany' => [$ch]]);
    assert_same(['service' => [$ch]], Alerts::routes());
    Channels::$factory = null;
});

test('Alerty: karencja, jedno zgłoszenie, przypomnienie po 6 h i wiadomość „ustąpiło”', function () {
    alerts_setup();
    $t = time();
    Settings::set('service_status', 'failed');
    assert_same(0, Alerts::process(['service'], $t, true), 'w karencji');
    assert_same(1, Alerts::process(['service'], $t + 61, true), 'po karencji');
    assert_same(0, Alerts::process(['service'], $t + 600, true), 'bez powtórzeń');
    assert_same(0, Alerts::process(['service'], $t + 3 * 3600, true), 'jeszcze nie przypomnienie');
    assert_same(1, Alerts::process(['service'], $t + 61 + 6 * 3600, true), 'przypomnienie');
    Settings::set('service_status', 'active');
    assert_same(1, Alerts::process(['service'], $t + 61 + 6 * 3600 + 30, true), 'ustąpiło');
    $subjects = alerts_pending();
    assert_same(3, count($subjects));
    assert_true(str_contains($subjects[0], 'Problem') || str_contains($subjects[0], 'Problem:'), 'temat problemu');
    assert_true(str_contains($subjects[2], 'Ustąpiło'), 'temat ustąpienia');
    assert_same(null, Alerts::state()['service']['since'], 'wpis zostaje na czas przerwy, ale problem nie trwa');
    Channels::$factory = null;
});

test('Alerty: nawrót w ciągu godziny nie powoduje drugiego zgłoszenia', function () {
    alerts_setup();
    $t = time();
    Settings::set('service_status', 'failed');
    Alerts::process(['service'], $t, true);
    assert_same(1, Alerts::process(['service'], $t + 61, true));
    Settings::set('service_status', 'active');
    assert_same(1, Alerts::process(['service'], $t + 120, true), 'ustąpiło');
    Settings::set('service_status', 'failed');
    Alerts::process(['service'], $t + 200, true);
    assert_same(0, Alerts::process(['service'], $t + 300, true), 'przerwa godzinna po ostatnim zgłoszeniu');
    assert_same(1, Alerts::process(['service'], $t + 61 + 3600 + 1, true), 'po godzinie, jeśli problem trwa');
    Channels::$factory = null;
});

test('Alerty: krótkie zakłócenie nie jest zgłaszane i nie wysyła „ustąpiło”', function () {
    alerts_setup();
    $t = time();
    Settings::set('service_status', 'failed');
    Alerts::process(['service'], $t, true);
    Settings::set('service_status', 'active');
    assert_same(0, Alerts::process(['service'], $t + 20, true));
    assert_same([], alerts_pending());
    assert_same([], Alerts::state());
    Channels::$factory = null;
});

test('Alerty: kolejka, błędy wysyłki, proces w tle, słaby sygnał', function () {
    alerts_setup();
    $now = now_db();
    $c = Alerts::conditions();
    assert_same([null, null, null, null, null, null], array_values($c));

    Db::insert('messages', ['direction' => 'out', 'phone' => '48601234567', 'body' => 'x', 'status' => 'queued', 'retries' => 0, 'created_at' => now_db(-1800), 'updated_at' => $now]);
    assert_same(['n' => 1], Alerts::conditions(['queue'])['queue']);

    foreach ([1, 2] as $i) {
        Db::insert('messages', ['direction' => 'out', 'phone' => '4860123456' . $i, 'body' => 'x', 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now]);
    }
    assert_same(null, Alerts::conditions(['failures'])['failures'], 'dwa błędy to jeszcze nie alarm');
    Db::insert('messages', ['direction' => 'out', 'phone' => '48601234569', 'body' => 'x', 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now]);
    assert_same(['n' => 3], Alerts::conditions(['failures'])['failures']);
    Db::insert('messages', ['direction' => 'out', 'phone' => '48601234560', 'body' => 'x', 'status' => 'delivered', 'created_at' => $now, 'updated_at' => now_db(5)]);
    assert_same(null, Alerts::conditions(['failures'])['failures'], 'udana wysyłka kończy serię');

    Db::update('messages', ['source' => 'notify'], "status = 'failed'");
    Db::update('messages', ['status' => 'failed'], "status = 'delivered'");
    assert_same(null, Alerts::conditions(['failures'])['failures'], 'błędy powiadomień SMS nie liczą się');

    Settings::set('worker_seen_at', now_db(-300));
    assert_true(Alerts::conditions(['worker'])['worker'] !== null, 'worker stoi');
    Db::update('modem_status', ['signal_pct' => 8], "modem = 'GSM1'");
    Status::forget();
    assert_same(['modem' => 'GSM1', 'pct' => 8], Alerts::conditions(['signal'])['signal']);
    Channels::$factory = null;
});

test('Alerty: strażnik zgłasza martwy proces w tle i od razu dostarcza', function () {
    $ch = alerts_setup();
    Settings::set('worker_seen_at', now_db(-600));
    Alerts::process(['worker'], time() - 200, true); // początek problemu
    Alerts::watchdog();
    assert_same(1, count(FakeChannel::$sent), 'powiadomienie wysłane od razu');
    assert_true(str_contains(FakeChannel::$sent[0]['subject'], 'Proces w tle'), 'temat');
    assert_same('alert', FakeChannel::$sent[0]['data']['type']);
    Channels::$factory = null;
});

test('Kanał SMS: wiadomość trafia do kolejki ze źródłem notify', function () {
    reset_db();
    [$id, $err] = Channels::save(null, 'sms', ['name' => 'Admin SMS', 'number' => '601 234 567']);
    assert_same(null, $err);
    assert_same('48601234567', Channels::config(Channels::find($id))['number']);
    assert_true(Channels::save(null, 'sms', ['name' => 'Zły', 'number' => 'abc'])[1] !== null, 'zły numer');
    assert_same(null, Channels::test($id));
    $m = Db::row("SELECT phone, source, status FROM messages WHERE direction = 'out'");
    assert_same(['48601234567', 'notify', 'queued'], [$m['phone'], $m['source'], $m['status']]);
});
