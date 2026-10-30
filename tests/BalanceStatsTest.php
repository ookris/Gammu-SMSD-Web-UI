<?php
test('Saldo: odczyt kwoty z odpowiedzi operatora', function () {
    $cases = [
        ['Stan konta: 12,34 zl. Wazne do 31.12.2026', 12.34],
        ['Twoje saldo to 123,45 zł. Konto ważne do 31.12.2026', 123.45],
        ['Saldo 5.20PLN', 5.2],
        ['Saldo: 1 234,50 zł', 1234.5],
        ["Saldo: 12\u{00A0}345,00 zł", 12345.0],
        ['Na koncie masz 5 zl 20 gr', 5.2],
        ['Masz 7 zł', 7.0],
        ['Stan konta 0,00', 0.0],
        ['Brak danych', null],
        ['', null],
    ];
    foreach ($cases as [$text, $expected]) {
        assert_same($expected, Balance::parse($text), $text);
    }
    assert_same(99.5, Balance::parse('Konto: abc, Saldo: 99,50 (promo 10 zł)', 'Saldo:\s*([0-9,]+)'));
    assert_true(Balance::validRegex('Saldo:\s*([0-9,]+)'), 'poprawne');
    assert_true(!Balance::validRegex('(niezamknięty'), 'niepoprawne');
});

test('Saldo: zlecenie zapytania, zapis odpowiedzi, brak duplikatów i alarm niskiego salda', function () {
    reset_db();
    Settings::set('service_status', 'active');
    Settings::set('service_checked_at', now_db());
    Db::insert('modem_status', ['modem' => 'GSM1', 'signal_pct' => 80, 'gammu_updated_at' => now_db(), 'updated_at' => now_db()]);
    Status::forget();
    assert_true(!Balance::enabled(), 'domyślnie wyłączone');
    Settings::set('balance_code', '*101#');
    Settings::set('balance_hours', 6);
    Settings::set('balance_threshold', '10');
    assert_true(Balance::enabled(), 'włączone');

    Balance::process();
    $req = Db::row("SELECT * FROM ussd_requests WHERE purpose = 'balance'");
    assert_same(['*101#', 'queued', 'GSM1'], [$req['code'], $req['status'], $req['modem']]);
    Balance::process();
    assert_same(1, (int) Db::val("SELECT COUNT(*) FROM ussd_requests WHERE purpose = 'balance'"), 'bez drugiego zapytania w trakcie odstępu');

    Db::update('ussd_requests', ['status' => 'answered', 'response' => 'Stan konta: 4,20 zl', 'answered_at' => now_db()], 'id = ?', [$req['id']]);
    assert_same(1, Balance::record());
    assert_same(0, Balance::record(), 'zapis tylko raz');
    $log = Db::row('SELECT amount, modem FROM balance_log');
    assert_same(['4.20', 'GSM1'], [$log['amount'], $log['modem']]);
    $m = Db::row("SELECT balance, balance_value FROM modem_status WHERE modem = 'GSM1'");
    assert_same(['Stan konta: 4,20 zl', '4.20'], [$m['balance'], $m['balance_value']]);
    assert_same(4.2, Balance::latest()['amount']);
    assert_same(1, count(Balance::series(30)));
    assert_same(['modem' => 'GSM1', 'amount' => '4,20', 'threshold' => '10,00'], Alerts::conditions(['balance'])['balance']);
    Settings::set('balance_threshold', '3');
    assert_same(null, Alerts::conditions(['balance'])['balance']);

    // brak odpowiedzi po 15 min – ponowna próba
    Settings::set('balance_requested', ['GSM1' => now_db(-1000)]);
    Db::update('ussd_requests', ['status' => 'timeout'], 'id = ?', [$req['id']]);
    Db::exec("DELETE FROM ussd_requests WHERE purpose = 'balance'");
    Db::exec('DELETE FROM balance_log');
    Balance::process();
    assert_same(1, (int) Db::val("SELECT COUNT(*) FROM ussd_requests WHERE purpose = 'balance'"), 'ponowienie bez odpowiedzi');
});

test('Statystyki: dzienne, miesięczne, doręczenia, najaktywniejsze numery i limit', function () {
    reset_db();
    $now = now_db();
    $ins = static fn (array $r) => Db::insert('messages', $r + ['body' => 'x', 'created_at' => $now, 'updated_at' => $now, 'parts' => 1]);
    $ins(['direction' => 'out', 'phone' => '48601000001', 'status' => 'delivered', 'report' => 1, 'sent_at' => $now]);
    $ins(['direction' => 'out', 'phone' => '48601000001', 'status' => 'undelivered', 'report' => 1, 'sent_at' => $now, 'parts' => 2]);
    $ins(['direction' => 'out', 'phone' => '48601000002', 'status' => 'sent', 'report' => 0, 'sent_at' => $now]);
    $ins(['direction' => 'out', 'phone' => '48601000002', 'status' => 'failed']);
    $ins(['direction' => 'in', 'phone' => '48601000001', 'status' => 'received', 'received_at' => $now]);
    Db::insert('calls', ['phone' => '48601000009', 'raw_number' => '', 'modem' => 'GSM1', 'received_at' => $now]);

    $daily = Stats::daily(30);
    assert_same(30, count($daily));
    assert_same([3, 1], [end($daily)['out'], end($daily)['in']]);
    assert_same(date('Y-m-d'), end($daily)['day']);
    $monthly = Stats::monthly(12);
    assert_same(12, count($monthly));
    assert_same([3, 1, date('Y-m')], [end($monthly)['out'], end($monthly)['in'], end($monthly)['month']]);
    assert_same(['delivered' => 1, 'undelivered' => 1, 'pct' => 50.0], Stats::delivery(30));
    assert_same('48601000001', Stats::top(5, 30)[0]['phone']);
    assert_same(1, Stats::calls(30));
    assert_same(4, Stats::monthParts(), 'części: 1 + 2 + 1 (błąd nie liczy się)');
    assert_same(null, Stats::limit());
    Settings::set('monthly_limit', 5);
    assert_same(['limit' => 5, 'used' => 4, 'pct' => 80], Stats::limit());
    assert_same(['pct' => 80, 'used' => 4, 'limit' => 5], Alerts::conditions(['limit'])['limit']);
    Settings::set('monthly_limit', 100);
    assert_same(null, Alerts::conditions(['limit'])['limit']);
});

test('Wykresy SVG: oś, słupki, linia, ładna wartość maksymalna', function () {
    assert_same([1.0, 5.0, 10.0, 20.0, 50.0, 1.0], array_map([Chart::class, 'niceMax'], [0.7, 4, 9, 11, 37, 0]));
    $svg = Chart::bars('Tytuł', 'Opis', ['01.01', '02.01', '03.01'], ['Wysłane' => [1, 0, 4], 'Odebrane' => [2, 2, 0]]);
    assert_true(str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>') && str_contains($svg, 'role="img"'), 'svg');
    assert_same(4, substr_count($svg, '<rect '), 'słupki tylko dla wartości > 0');
    assert_true(str_contains($svg, 'Wysłane: 4'), 'podpis słupka');
    $line = Chart::line('Saldo', 'Opis', [['t' => 1000, 'y' => 5.0], ['t' => 2000, 'y' => 9.5]], 0, 3000, 4.0, ' zł');
    assert_same(2, substr_count($line, '<circle '));
    assert_true(str_contains($line, 'chart-limit') && str_contains($line, '<polyline'), 'linia i próg');
    assert_true(!str_contains(Chart::line('x', 'y', [], 0, 10), '<polyline'), 'pusty wykres');
});

test('Saldo: każdy modem pytany osobno, po jednym USSD na przebieg, alarm wskazuje modem', function () {
    reset_db();
    Settings::set('service_status', 'active');
    Settings::set('service_checked_at', now_db());
    foreach (['GSM1', 'GSM2'] as $id) {
        Db::insert('modem_status', ['modem' => $id, 'signal_pct' => 80, 'gammu_updated_at' => now_db(), 'updated_at' => now_db()]);
    }
    Status::forget();
    Settings::set('balance_code', '*101#');
    Settings::set('balance_hours', 6);
    Settings::set('balance_threshold', '10');
    Balance::process();
    assert_same(['GSM1'], Db::col("SELECT modem FROM ussd_requests WHERE purpose = 'balance'"), 'pierwszy modem');
    Db::update('ussd_requests', ['status' => 'answered', 'response' => '20,00 zl', 'answered_at' => now_db()], "purpose = 'balance'");
    Balance::process();
    assert_same(['GSM1', 'GSM2'], Db::col("SELECT modem FROM ussd_requests WHERE purpose = 'balance' ORDER BY id"), 'drugi modem w następnym przebiegu');
    Db::update('ussd_requests', ['status' => 'answered', 'response' => '3,00 zl', 'answered_at' => now_db()], "modem = 'GSM2'");
    Balance::record();
    assert_same(['GSM1', 'GSM2'], Balance::modemsWithData());
    assert_same([20.0, 3.0], [Balance::latest('GSM1')['amount'], Balance::latest('GSM2')['amount']]);
    assert_same('GSM2', Alerts::conditions(['balance'])['balance']['modem'], 'niskie saldo tylko w drugim modemie');
    assert_same(1, count(Balance::series(30, 'GSM2')));
});

test('Statystyki: limit miesięczny nie liczy wiadomości zaplanowanych na kolejny miesiąc', function () {
    reset_db();
    $now = now_db();
    Db::insert('messages', ['direction' => 'out', 'phone' => '48601000001', 'body' => 'x', 'status' => 'scheduled', 'parts' => 3, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('first day of next month 10:00')), 'created_at' => $now, 'updated_at' => $now]);
    Db::insert('messages', ['direction' => 'out', 'phone' => '48601000001', 'body' => 'x', 'status' => 'queued', 'parts' => 1, 'scheduled_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    assert_same(1, Stats::monthParts());
});

test('Saldo: nierealnie duża „kwota” nie blokuje synchronizacji', function () {
    reset_db();
    assert_same(null, Balance::parse('Konto 1234567890 zl'), 'poza zakresem DECIMAL(10,2) – to nie kwota');
    Db::insert('ussd_requests', ['code' => '*101#', 'status' => 'answered', 'purpose' => 'balance', 'response' => 'Konto 1234567890 zl', 'created_at' => now_db(), 'answered_at' => now_db(), 'modem' => 'GSM1']);
    Settings::set('balance_regex', '(\d+)');
    $r = Sync::run(true);
    assert_same(0, $r['errors'], 'synchronizacja przeszła');
    assert_same(1, (int) Db::val('SELECT COUNT(*) FROM balance_log'), 'odpowiedź zapisana (bez kwoty) i nie ponawiana');
    assert_same(0, Balance::record());
});

test('Wykresy: oś liczb całkowitych ma całkowite podziałki; limit 100 % to osobny alarm', function () {
    assert_same([4, 4, 8, 12, 20, 40, 80, 120, 200], array_map([Chart::class, 'niceCount'], [0, 3, 5, 9, 13, 21, 77, 81, 150]));
    $svg = Chart::bars('t', 'd', ['a'], ['x' => [3]]);
    assert_true(!str_contains($svg, '1.25') && !str_contains($svg, '0.75'), 'bez ułamków na osi');
    reset_db();
    Settings::set('monthly_limit', 4);
    Db::insert('messages', ['direction' => 'out', 'phone' => '48601000001', 'body' => 'x', 'status' => 'sent', 'parts' => 4, 'sent_at' => now_db(), 'created_at' => now_db(), 'updated_at' => now_db()]);
    $c = Alerts::conditions(['limit', 'limit_full']);
    assert_same([100, 100], [$c['limit']['pct'], $c['limit_full']['pct']]);
});
