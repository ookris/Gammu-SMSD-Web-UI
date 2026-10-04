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

test('zapis pliku z końcami linii CRLF nie jest zgłaszany jako nieaktualny', function () {
    reset_db();
    write_conf("[smsd]\r\nservice = sql\r\nphoneid = GSM1\r\n");
    $base = GammuConf::fingerprint(GammuConf::load(true)->text()); // tak jak formularz – z tekstu po parserze
    GammuConf::save("[smsd]\nservice = sql\nphoneid = GSM2\n", 'test', $base);
    assert_contains('GSM2', file_get_contents((string) cfg('gammu_conf')));
});

test('zapis nie tworzy pliku konfiguracji, który zniknął', function () {
    $path = (string) cfg('gammu_conf');
    @unlink($path);
    try {
        GammuConf::save("[smsd]\n", 'test', GammuConf::fingerprint(''));
        throw new AssertionFailed('brak wyjątku');
    } catch (RuntimeException $e) {
        assert_contains('Nie można odczytać', $e->getMessage());
    }
    assert_same(false, file_exists($path));
});

test('oś czasu: zdarzenia z tej samej sekundy rosnąco po id', function () {
    reset_db();
    $at = now_db(-60);
    foreach ([1, 2, 3] as $i) {
        Db::insert('calls', ['phone' => '48601234567', 'raw_number' => '+48601234567', 'modem' => 'GSM1', 'received_at' => $at]);
    }
    $ids = array_column(Threads::timeline('48601234567', 10), 'id');
    assert_same(array_map('intval', $ids), array_map('intval', Db::col('SELECT id FROM calls ORDER BY id')));
});

test('filtry dat: tylko istniejące daty, odrzucone czyszczone', function () {
    assert_same('2026-10-04', valid_date('2026-10-04'));
    foreach (['2026-99-99', '2026-02-30', '0000-01-01', '26-10-04', 'x'] as $d) {
        assert_same(null, valid_date($d), $d);
    }
    assert_same(['from' => '', 'to' => '2026-10-04'], clean_dates(['from' => '2026-99-99', 'to' => '2026-10-04'], ['from', 'to']));
});

/** Atrapa systemu plików: plik da się utworzyć, ale zapis zwraca 0 bajtów (pełny dysk). */
final class FailingWriteStream
{
    public static array $files = [];
    public $context;
    private string $path = '';

    public function stream_open(string $path, string $mode): bool
    {
        if (isset(self::$files[$path])) {
            return false;
        }
        self::$files[$path] = '';
        $this->path = $path;
        return true;
    }

    public function stream_write(string $data): int
    {
        trigger_error('fwrite(): write failed, no space left on device', E_USER_WARNING); // jak prawdziwy błąd I/O
        return 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);
        return true;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['mode' => 0100600, 'size' => 0] : false;
    }
}

test('nieudany zapis kopii: wyjątek i usunięty niepełny plik (także gdy ostrzeżenia są wyjątkami)', function () {
    stream_wrapper_register('failfs', FailingWriteStream::class);
    try {
        GammuConf::createFile('failfs://kopia', 'password = tajne');
        throw new AssertionFailed('brak wyjątku');
    } catch (RuntimeException $e) {
        assert_contains('Nie można zapisać', $e->getMessage());
    } finally {
        stream_wrapper_unregister('failfs');
    }
    assert_same([], FailingWriteStream::$files);
});
