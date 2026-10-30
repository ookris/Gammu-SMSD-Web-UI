<?php
function api_token(string $ips = ''): array
{
    reset_db();
    FakeChannel::$sent = [];
    [$id, $plain, $err] = ApiTokens::create('Zabbix', $ips);
    assert_same(null, $err);
    return [$id, $plain];
}

/** Wywołanie Api::send w procesie testowym: exit w reply() przechwytujemy przez uruchomienie w osobnym PHP. */
function api_call(string $method, string $route, ?string $token, array $params = [], string $ip = '192.168.1.5'): array
{
    $script = tempnam(sys_get_temp_dir(), 'api') . '.php';
    $cfg = var_export(app_config(), true);
    file_put_contents($script, '<?php
require ' . var_export(APP_ROOT . '/src/bootstrap.php', true) . ';
app_config(' . $cfg . ');
$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
$_SERVER["REMOTE_ADDR"] = ' . var_export($ip, true) . ';
' . ($token !== null ? '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";' : '') . '
$_SERVER["CONTENT_TYPE"] = "application/x-www-form-urlencoded";
$_POST = $_GET = ' . var_export($params, true) . ';
Api::handle(' . var_export($route, true) . ');');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $json = json_decode((string) $out, true);
    assert_true(is_array($json), 'odpowiedź JSON: ' . $out);
    return $json;
}

test('API: tokeny – skrót w bazie, jawny token tylko raz, unieważnianie', function () {
    [$id, $plain] = api_token();
    assert_true(str_starts_with($plain, 'sg_') && strlen($plain) === 43, 'format');
    $row = Db::row('SELECT * FROM api_tokens WHERE id = ?', [$id]);
    assert_same(hash('sha256', $plain), $row['token_hash']);
    assert_true(!str_contains(json_encode($row), $plain), 'jawny token nie leży w bazie');
    assert_true(is_array(ApiTokens::authenticate($plain, '10.0.0.1')), 'poprawny token');
    assert_same('invalid', ApiTokens::authenticate('sg_zly', '10.0.0.1'));
    assert_same('invalid', ApiTokens::authenticate('', '10.0.0.1'));
    assert_true(Db::val('SELECT last_used_at FROM api_tokens WHERE id = ?', [$id]) !== null, 'ostatnie użycie');
    ApiTokens::revoke($id);
    assert_same('invalid', ApiTokens::authenticate($plain, '10.0.0.1'));
    assert_true(ApiTokens::create('', '')[2] !== null, 'brak nazwy');
    assert_true(ApiTokens::create('X', '999.1.1.1')[2] !== null, 'zły adres IP');
});

test('API: lista dozwolonych adresów IP i sieci CIDR', function () {
    $allowed = [['192.168.1.0/24', '192.168.1.77'], ['10.0.0.5', '10.0.0.5'], ['10.0.0.0/8', '10.200.3.4'], ['fd00::/8', 'fd12::1'], ['192.168.1.5/32', '192.168.1.5'], ['172.16.0.0/12', '172.31.255.1']];
    foreach ($allowed as [$list, $ip]) {
        assert_true(ApiTokens::ipAllowed($ip, $list), "$ip w $list");
    }
    foreach ([['192.168.1.0/24', '192.168.2.1'], ['10.0.0.5', '10.0.0.6'], ['fd00::/8', '192.168.1.1'], ['172.16.0.0/12', '172.32.0.1'], ['192.168.1.0/25', '192.168.1.200']] as [$list, $ip]) {
        assert_true(!ApiTokens::ipAllowed($ip, $list), "$ip poza $list");
    }
    [, $plain] = api_token('192.168.1.0/24, 10.0.0.5');
    assert_true(is_array(ApiTokens::authenticate($plain, '10.0.0.5')), 'dozwolony');
    assert_same('ip', ApiTokens::authenticate($plain, '8.8.8.8'));
    assert_same(['192.168.1.0/24', '10.0.0.5'], ApiTokens::parseIps('192.168.1.0/24, 10.0.0.5'));
    assert_same(null, ApiTokens::parseIps('192.168.1.0/33'));
});

test('API: /api/send tworzy wiadomości ze źródłem api, /api/status je opisuje', function () {
    [$id, $plain] = api_token();
    $r = api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'Alarm: dysk pełny', 'report' => '0', 'priority' => 'high', 'valid_for' => '60']);
    assert_same(true, $r['ok']);
    assert_same(1, $r['count']);
    $m = Db::row('SELECT * FROM messages WHERE id = ?', [$r['ids'][0]]);
    assert_same(['48601234567', 'api', (int) $id, 'queued', 0, 60, 10], [$m['phone'], $m['source'], (int) $m['api_token_id'], $m['status'], (int) $m['report'], (int) $m['validity_min'], (int) $m['priority']]);
    $s = api_call('GET', 'status', $plain, ['id' => (string) $r['ids'][0]]);
    assert_same(['+48601234567', 'queued'], [$s['to'], $s['status']]);
    $other = Db::insert('messages', ['direction' => 'out', 'phone' => '48600000000', 'body' => 'x', 'status' => 'sent', 'created_at' => now_db(), 'updated_at' => now_db()]);
    assert_same(false, api_call('GET', 'status', $plain, ['id' => (string) $other])['ok'], 'wiadomości spoza API są niewidoczne');
});

test('API: wielu odbiorców (dławienie i partia), błędy walidacji, uwierzytelnianie, limit', function () {
    [$id, $plain] = api_token();
    $r = api_call('POST', 'send', $plain, ['to' => '601000001, 601000002;601000003', 'text' => 'Do wielu']);
    assert_same([true, 3], [$r['ok'], $r['count']]);
    assert_true($r['batch'] !== null, 'partia');
    assert_same(3, (int) Db::val("SELECT COUNT(*) FROM messages WHERE batch_id = ? AND source = 'api'", [$r['batch']]));

    $bad = api_call('POST', 'send', $plain, ['to' => 'abc', 'text' => 'x']);
    assert_same(false, $bad['ok']);
    assert_true(count($bad['errors']) >= 1, 'błędy');
    assert_same(false, api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => ''])['ok'], 'pusty tekst');
    assert_same(false, api_call('POST', 'send', 'sg_zly', ['to' => '601234567', 'text' => 'x'])['ok'], 'zły token');
    assert_same(false, api_call('POST', 'send', null, ['to' => '601234567', 'text' => 'x'])['ok'], 'brak tokenu');
    assert_same(false, api_call('GET', 'send', $plain)['ok'], 'zła metoda');
    assert_same(false, api_call('POST', 'nieistnieje', $plain)['ok'], 'zła trasa');

    Db::update('api_tokens', ['allowed_ips' => '10.0.0.0/8'], 'id = ?', [$id]);
    assert_same(false, api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'x'], '192.168.1.5')['ok'], 'IP spoza listy');
    assert_same(true, api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'x'], '10.1.1.1')['ok'], 'IP z listy');

    Db::exec('UPDATE messages SET created_at = ?', [now_db()]);
    Db::exec('UPDATE messages SET api_token_id = ?', [$id]);
    for ($i = 0; $i < Api::PER_MINUTE; $i++) {
        Db::insert('messages', ['direction' => 'out', 'phone' => '48600000001', 'body' => 'x', 'status' => 'sent', 'api_token_id' => $id, 'created_at' => now_db(), 'updated_at' => now_db()]);
    }
    assert_same(false, api_call('POST', 'send', $plain, ['to' => '601234567', 'text' => 'x'], '10.1.1.1')['ok'], 'limit na minutę');
});
