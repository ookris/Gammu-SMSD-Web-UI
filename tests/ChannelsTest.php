<?php
final class FakeChannel implements Channel
{
    public static array $sent = [];
    public static ?string $fail = null;

    public function send(array $msg): void
    {
        if (self::$fail !== null) {
            throw new RuntimeException(self::$fail);
        }
        self::$sent[] = $msg;
    }
}

function fake_channel(): int
{
    reset_db();
    FakeChannel::$sent = [];
    FakeChannel::$fail = null;
    Channels::$factory = static fn (array $row) => $row['name'] === 'fake' ? new FakeChannel() : null;
    return Db::insert('channels', ['type' => 'webhook', 'name' => 'fake', 'config' => '{}', 'enabled' => 1, 'created_at' => now_db()]);
}

test('Crypto: szyfrowanie sekretu w obie strony, losowy IV, uszkodzone dane', function () {
    $e1 = Crypto::encrypt('tajne hasło ąę');
    assert_true(str_starts_with($e1, 'enc1:') && !str_contains($e1, 'tajne'), 'zaszyfrowane');
    assert_true($e1 !== Crypto::encrypt('tajne hasło ąę'), 'różny IV');
    assert_same('tajne hasło ąę', Crypto::decrypt($e1));
    assert_same('', Crypto::encrypt(''));
    assert_same('', Crypto::decrypt('jawny tekst'));
    assert_same('', Crypto::decrypt(substr($e1, 0, -4) . 'AAAA'));
});

test('Kanały: zapis e-mail i webhooka, sekret zaszyfrowany i zachowywany przy edycji', function () {
    reset_db();
    [$id, $err] = Channels::save(null, 'email', ['name' => 'Admin', 'to' => 'a@example.com; b@example.com, zły']);
    assert_same(null, $err);
    assert_same('a@example.com, b@example.com', Channels::config(Channels::find($id))['to']);
    assert_true(Channels::save(null, 'email', ['name' => 'X', 'to' => 'zły'])[1] !== null, 'brak poprawnego adresu');
    assert_true(Channels::save(null, 'email', ['name' => '', 'to' => 'a@example.com'])[1] !== null, 'brak nazwy');

    [$w, $err] = Channels::save(null, 'webhook', ['name' => 'HA', 'url' => 'https://ha.local/hook', 'secret' => 'abc']);
    assert_same(null, $err);
    $secret = Channels::config(Channels::find($w))['secret'];
    assert_same('abc', Crypto::decrypt($secret));
    Channels::save($w, 'webhook', ['name' => 'HA2', 'url' => 'https://ha.local/hook2', 'secret' => '']);
    assert_same($secret, Channels::config(Channels::find($w))['secret']);
    Channels::save($w, 'webhook', ['name' => 'HA2', 'url' => 'https://ha.local/hook2', 'secret_clear' => true]);
    assert_same('', Channels::config(Channels::find($w))['secret']);
    assert_true(Channels::save(null, 'webhook', ['name' => 'Zły', 'url' => 'ftp://x'])[1] !== null, 'tylko http(s)');
});

test('Webhook: podpis HMAC treści i blokada adresów loopback / link-local', function () {
    $secret = Crypto::encrypt('klucz');
    $w = new WebhookChannel(['url' => 'https://example.com/h', 'secret' => $secret]);
    [$body, $headers] = $w->request(['subject' => 's', 'text' => 'Zażółć', 'data' => ['type' => 'sms', 'from' => '+48601234567']]);
    assert_same(['type' => 'sms', 'from' => '+48601234567', 'text' => 'Zażółć'], json_decode($body, true));
    assert_true(in_array('X-Smsgui-Signature: sha256=' . hash_hmac('sha256', $body, 'klucz'), $headers, true), 'podpis');
    [, $h2] = (new WebhookChannel(['url' => 'https://example.com/h']))->request(['subject' => '', 'text' => '', 'data' => []]);
    assert_same(0, count(array_filter($h2, fn ($x) => str_starts_with($x, 'X-Smsgui'))));
    foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest', 'http://[::1]/x', 'http://0.0.0.0/', 'http://[::ffff:127.0.0.1]/', 'ftp://1.2.3.4/'] as $bad) {
        assert_true(WebhookChannel::checkUrl($bad) !== null, "zablokowany: $bad");
    }
    assert_same(null, WebhookChannel::checkUrl('http://192.168.1.10:8123/api/webhook/x'));
});

test('Dostarczenia: sukces, ponawianie z odstępem, status failed po 5 próbach, wyłączony kanał czeka', function () {
    $ch = fake_channel();
    $a = Deliveries::enqueue($ch, 'test', ['subject' => 'T', 'text' => 'treść', 'data' => ['type' => 'x']]);
    assert_same(1, Deliveries::process());
    assert_same('treść', FakeChannel::$sent[0]['text']);
    assert_same(['sent', 1], [Db::val('SELECT status FROM deliveries WHERE id = ?', [$a]), (int) Db::val('SELECT attempts FROM deliveries WHERE id = ?', [$a])]);
    assert_same(0, Deliveries::process(), 'już wysłane');

    FakeChannel::$fail = 'serwer nie działa';
    $b = Deliveries::enqueue($ch, 'test', ['subject' => 'T', 'text' => 'x']);
    assert_same(0, Deliveries::process());
    $row = Db::row('SELECT status, attempts, last_error, next_try_at > ? AS later FROM deliveries WHERE id = ?', [now_db(), $b]);
    assert_same(['pending', 1, 'serwer nie działa', 1], [$row['status'], (int) $row['attempts'], $row['last_error'], (int) $row['later']]);
    assert_same(0, Deliveries::process(), 'odstęp jeszcze trwa');
    for ($i = 2; $i <= Deliveries::MAX_ATTEMPTS; $i++) {
        Db::update('deliveries', ['next_try_at' => now_db(-5)], 'id = ?', [$b]);
        Deliveries::process();
    }
    assert_same(['failed', Deliveries::MAX_ATTEMPTS], [Db::val('SELECT status FROM deliveries WHERE id = ?', [$b]), (int) Db::val('SELECT attempts FROM deliveries WHERE id = ?', [$b])]);
    assert_same('serwer nie działa', Db::val('SELECT last_error FROM channels WHERE id = ?', [$ch]));

    FakeChannel::$fail = null;
    Channels::setEnabled($ch, false);
    $c = Deliveries::enqueue($ch, 'test', ['subject' => 'T', 'text' => 'x']);
    assert_same(0, Deliveries::process());
    Channels::setEnabled($ch, true);
    assert_same(1, Deliveries::process());
    Channels::delete($ch);
    assert_same(0, (int) Db::val('SELECT COUNT(*) FROM deliveries'), 'usunięcie kanału usuwa jego dostarczenia');
    Channels::$factory = null;
});

test('Kanały: „Wyślij test” zapisuje wynik w kanale', function () {
    $ch = fake_channel();
    assert_same(null, Channels::test($ch));
    assert_true(Db::val('SELECT last_ok_at FROM channels WHERE id = ?', [$ch]) !== null, 'last_ok_at');
    FakeChannel::$fail = 'błąd';
    assert_same('błąd', Channels::test($ch));
    assert_same('błąd', Db::val('SELECT last_error FROM channels WHERE id = ?', [$ch]));
    Channels::$factory = null;
});

test('Sync::run przetwarza kolejkę dostarczeń', function () {
    $ch = fake_channel();
    Deliveries::enqueue($ch, 'test', ['subject' => 'T', 'text' => 'z workera']);
    $r = Sync::run(true);
    assert_same(1, $r['deliveries']);
    assert_same('z workera', FakeChannel::$sent[0]['text']);
    Channels::$factory = null;
});

test('Poczta: ustawienia SMTP, szyfrowanie hasła, walidacja', function () {
    reset_db();
    assert_same('sendmail', Channels::mailSettings()['mode']);
    assert_same(null, Channels::saveMail(['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => '465', 'secure' => 'ssl', 'user' => 'u', 'pass' => 'p4ss', 'from' => 'bramka@example.com']));
    $s = Channels::mailSettings();
    assert_same(['smtp', 'smtp.example.com', '465', 'ssl', 'p4ss'], [$s['mode'], $s['host'], $s['port'], $s['secure'], $s['pass']]);
    assert_true(!str_contains((string) Settings::get('mail'), 'p4ss'), 'hasło nie leży w bazie jawnie');
    Channels::saveMail(['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => '465', 'pass' => '']);
    assert_same('p4ss', Channels::mailSettings()['pass']);
    Channels::saveMail(['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => '465', 'pass_clear' => true]);
    assert_same('', Channels::mailSettings()['pass']);
    assert_true(Channels::saveMail(['mode' => 'smtp', 'host' => '', 'port' => '25']) !== null, 'brak serwera');
    assert_true(Channels::saveMail(['mode' => 'smtp', 'host' => 'a.b', 'port' => '99999']) !== null, 'zły port');
    assert_true(Channels::saveMail(['mode' => 'sendmail', 'from' => 'zły']) !== null, 'zły nadawca');
    assert_same(null, Channels::saveMail(['mode' => 'sendmail']));
});

test('Dostarczenia: oczekujące dłużej niż 48 h wygasają zamiast wysłać się masowo po włączeniu kanału', function () {
    $ch = fake_channel();
    $old = Deliveries::enqueue($ch, 'alert', ['subject' => 'stare', 'text' => 'x']);
    Db::update('deliveries', ['created_at' => now_db(-49 * 3600)], 'id = ?', [$old]);
    $fresh = Deliveries::enqueue($ch, 'alert', ['subject' => 'świeże', 'text' => 'x']);
    assert_same(1, Deliveries::process());
    assert_same('failed', Db::val('SELECT status FROM deliveries WHERE id = ?', [$old]));
    assert_same(['świeże'], array_column(FakeChannel::$sent, 'subject'));
    Channels::$factory = null;
});
