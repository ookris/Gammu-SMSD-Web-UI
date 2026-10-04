<?php
// Testy poprawek z przeglądu kodu (2026-10-04)

function write_conf(string $text): void
{
    file_put_contents((string) cfg('gammu_conf'), $text);
    GammuConf::forget();
}

test('transakcja zagnieżdżona dołącza do zewnętrznej – błąd wycofuje całą wysyłkę', function () {
    reset_db();
    try {
        Db::tx(function () {
            Outbox::create(['phone' => '48601234567', 'body' => 'pierwsza']);
            Outbox::create(['phone' => '48502113908', 'body' => '']); // pusta treść – wyjątek
        });
    } catch (InvalidArgumentException) {
    }
    assert_same([0, 0], [(int) Db::val('SELECT COUNT(*) FROM messages'), (int) Db::val('SELECT COUNT(*) FROM {g}.outbox')]);
});

test('zapis konfiguracji: plik zmieniony w międzyczasie → odmowa, bez kopii i bez zapisu', function () {
    reset_db();
    write_conf("[smsd]\nservice = sql\n");
    $base = GammuConf::fingerprint("[smsd]\nservice = sql\n");
    write_conf("[smsd]\nservice = sql\nphoneid = INNA\n"); // zmiana z innej sesji
    $before = count(GammuConf::backups());
    try {
        GammuConf::save("[smsd]\nservice = sql\nphoneid = MOJA\n", 'test', $base);
        throw new AssertionFailed('brak wyjątku');
    } catch (ConfStaleException) {
    }
    assert_contains('INNA', file_get_contents((string) cfg('gammu_conf')));
    assert_same($before, count(GammuConf::backups()));
    GammuConf::save("[smsd]\nservice = sql\nphoneid = MOJA\n", 'test', GammuConf::fingerprint("[smsd]\nservice = sql\nphoneid = INNA\n"));
    assert_contains('MOJA', file_get_contents((string) cfg('gammu_conf')));
});

test('kopie tworzone od razu z prawami 0600 i bez nadpisywania istniejących', function () {
    $f = APP_ROOT . '/var/test/kopia-' . getmypid();
    @unlink($f);
    GammuConf::createFile($f, 'password = tajne');
    assert_same('0600', substr(sprintf('%o', fileperms($f)), -4));
    try {
        GammuConf::createFile($f, 'inna treść');
        throw new AssertionFailed('nadpisano istniejący plik');
    } catch (RuntimeException) {
    }
    assert_same('password = tajne', file_get_contents($f));
    unlink($f);
});

test('formularz: niezmieniona wartość spoza listy jest dozwolona, nowa – odrzucona', function () {
    $conf = GammuConf::parse("[gammu]\nconnection = at\n[smsd]\ndebuglevel = 4\n");
    [, $changes, $errors] = GammuConfForm::apply($conf, ['smsd_debuglevel' => '4', 'gammu_connection' => 'at', 'smsd_phoneid' => 'GSM2']);
    assert_same([], $errors);
    assert_same(['phoneid (domyślnie) → GSM2'], $changes);
    [, , $errors] = GammuConfForm::apply($conf, ['smsd_debuglevel' => '7', 'gammu_connection' => 'at']);
    assert_true(isset($errors['smsd_debuglevel']));
});

test('hook połączeń: PhoneID zacytowany dla powłoki', function () {
    write_conf("[smsd]\nservice = sql\nphoneid = GSM 1;touch /tmp/x\n");
    $hook = (string) Calls::confChange(true)->get('smsd', 'runonincomingcall');
    assert_true(str_ends_with($hook, " --phone='GSM 1;touch /tmp/x'"), $hook);
});

test('deinstalacja usuwa tylko ustawienia panelu', function () {
    $cmd = (string) cfg('hook.command');
    write_conf("[smsd]\nservice = sql\nhangupcalls = yes\nrunonincomingcall = /usr/local/bin/moj-skrypt\nexcludenumbersfile = /etc/moja-lista\n");
    ob_start();
    Setup::unhook();
    ob_end_clean();
    assert_contains('moj-skrypt', file_get_contents((string) cfg('gammu_conf')));
    assert_contains('/etc/moja-lista', file_get_contents((string) cfg('gammu_conf')));
    write_conf("[smsd]\nservice = sql\nhangupcalls = yes\nrunonincomingcall = $cmd --phone='GSM1'\nexcludenumbersfile = " . Blocklist::path() . "\n");
    ob_start();
    Setup::unhook();
    ob_end_clean();
    assert_same("[smsd]\nservice = sql\n", file_get_contents((string) cfg('gammu_conf')));
    foreach (glob(cfg('gammu_conf') . '.smsgui-*') as $b) {
        assert_same('0600', substr(sprintf('%o', fileperms($b)), -4));
        unlink($b);
    }
});

test('godziny okna wysyłki: tylko 00:00–23:59', function () {
    foreach (['08:00' => true, '23:59' => true, '00:00' => true, '24:00' => false, '25:00' => false, '12:60' => false, '8:00' => false] as $t => $ok) {
        assert_same($ok, Recipients::validTime((string) $t), "godzina $t");
    }
});

test('oś czasu rozmowy: limit obejmuje też połączenia', function () {
    reset_db();
    for ($i = 0; $i < 30; $i++) {
        Db::insert('calls', ['phone' => '48601234567', 'raw_number' => '+48601234567', 'modem' => 'GSM1', 'received_at' => now_db(-1000 + $i)]);
    }
    Outbox::create(['phone' => '48601234567', 'body' => 'najnowsza']);
    $items = Threads::timeline('48601234567', 10);
    assert_same(10, count($items));
    assert_same('najnowsza', $items[9]['body'] ?? null);
});
