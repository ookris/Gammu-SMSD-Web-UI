<?php
test('różnice: zmiana jednej linii = „-” i „+” w tym miejscu', function () {
    $d = Diff::lines("a\nb\nc\n", "a\nB\nc\n");
    assert_same([[' ', 'a'], ['-', 'b'], ['+', 'B'], [' ', 'c']], $d);
    assert_same(2, Diff::changed($d));
});

test('różnice: dodanie i usunięcie, kontekst z „…”', function () {
    $old = implode("\n", range(1, 20));
    $new = str_replace("\n10\n", "\n10\nnowa\n", $old);
    $new = str_replace("\n18\n", "\n", $new);
    $ctx = Diff::context(Diff::lines($old, $new), 1);
    assert_same([' ', '+', ' ', '…', ' ', '-', ' '], array_column($ctx, 0));
    assert_same(['10', 'nowa', '11', '', '17', '18', '19'], array_column($ctx, 1));
    assert_same(2, Diff::changed(Diff::lines($old, $new)));
});

test('czarna lista: plik z wariantami numerów, zapis atomowy, zgodność', function () {
    reset_db();
    @unlink(Blocklist::path());
    Blocklist::add('601 234 567', 'spam');
    Blocklist::add('PROMO-SMS');
    Blocklist::add('+44 7911 123456');
    assert_same(false, Blocklist::inSync());
    Blocklist::write();
    assert_same("+447911123456\n+48601234567\n00447911123456\n0048601234567\n447911123456\n48601234567\n601234567\nPROMO-SMS\n", file_get_contents(Blocklist::path()));
    assert_true(Blocklist::inSync());
    assert_true(Blocklist::isBlocked('48601234567'));
    assert_same('To nie jest numer ani nazwa nadawcy: 12', Blocklist::add('12')[1]);
});

test('kopie zapasowe: limit, nazwy tylko wg wzorca', function () {
    reset_db();
    Settings::set('backup_keep', '2');
    foreach (glob(GammuConf::backupDir() . '/gammu-smsdrc.*') ?: [] as $f) {
        unlink($f);
    }
    foreach (['a', 'b', 'c'] as $i => $x) {
        GammuConf::backup($x, "zmiana $x");
    }
    assert_same(2, count(GammuConf::backups()));
    assert_same(false, GammuConf::validBackupName('../../etc/passwd'));
    assert_same(null, GammuConf::readBackup('gammu-smsdrc.20260101-000000/../x'));
});
