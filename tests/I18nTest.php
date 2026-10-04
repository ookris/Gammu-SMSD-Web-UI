<?php
/** Pliki PHP, w których teksty interfejsu muszą iść przez t() (bez komentarzy – sprawdza tokenizer). */
function i18n_files(): array
{
    return array_merge(glob(APP_ROOT . '/views/*.php'), glob(APP_ROOT . '/views/partials/*.php'), glob(APP_ROOT . '/src/pages/*.php'), [APP_ROOT . '/public/index.php']);
}

/** Napisy i HTML z polskimi literami (pominięte komentarze); [plik:linia => tekst]. */
function i18n_polish_literals(string $file): array
{
    $found = [];
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $tok[1])) {
            $found[basename(dirname($file)) . '/' . basename($file) . ':' . $tok[2]] = mb_substr(trim($tok[1]), 0, 60);
        }
    }
    return $found;
}

function i18n_placeholders(string|array $text): array
{
    preg_match_all('/\{[a-z_]+\}/', implode("\n", (array) $text), $m);
    $p = array_unique($m[0]);
    sort($p);
    return $p;
}

test('pl i en mają te same klucze, rodzaje wpisów i {zmienne}', function () {
    $pl = require APP_ROOT . '/resources/lang/pl.php';
    $en = require APP_ROOT . '/resources/lang/en.php';
    assert_same([], array_keys(array_diff_key($pl, $en)), 'brak w en.php');
    assert_same([], array_keys(array_diff_key($en, $pl)), 'brak w pl.php');
    foreach ($pl as $k => $v) {
        assert_same(is_array($v), is_array($en[$k]), "rodzaj wpisu: $k");
        assert_same(i18n_placeholders($v), i18n_placeholders($en[$k]), "zmienne w: $k");
    }
});

test('każdy klucz użyty w kodzie istnieje w pl.php', function () {
    $pl = require APP_ROOT . '/resources/lang/pl.php';
    $missing = [];
    $files = array_merge(i18n_files(), glob(APP_ROOT . '/src/*.php'));
    foreach ($files as $file) {
        preg_match_all("/\\b(?:t|tn|msg_key)\\('([a-z0-9_.]+)'\\s*[,)]/", file_get_contents($file), $m);
        foreach ($m[1] as $key) {
            if (!isset($pl[$key])) {
                $missing[] = basename($file) . ": $key";
            }
        }
    }
    assert_same([], array_values(array_unique($missing)));
});

test('widoki i strony bez tekstów na sztywno (polskie litery poza t())', function () {
    $found = [];
    foreach (i18n_files() as $file) {
        $found += i18n_polish_literals($file);
    }
    assert_same([], $found);
});

test('formy liczebnika, daty i liczby w obu językach', function () {
    try {
        lang('pl');
        assert_same('Pozostało prób: 3, potem blokada na 15 minut.', t('auth.left', ['n' => 3]));
        assert_same('brak.klucza', t('brak.klucza'));
        assert_same('1 234,5', fmt_num(1234.5, 1));
        assert_same('1,5 godz.', fmt_duration(5400));
        assert_same('Niedziela, 4 października 2026', fmt_long_date(mktime(12, 0, 0, 10, 4, 2026)));
        lang('en');
        assert_same('Attempts left: 3, then a 15-minute lockout.', t('auth.left', ['n' => 3]));
        assert_same('1,234.5', fmt_num(1234.5, 1));
        assert_same('2 h', fmt_duration(7200));
        assert_same('Sunday, October 4, 2026', fmt_long_date(mktime(12, 0, 0, 10, 4, 2026)));
        assert_same('4 Oct 2026 12:00', fmt_date(mktime(12, 0, 0, 10, 4, 2026), 'H:i'));
    } finally {
        lang('pl');
    }
});

test('tekst z bazy zapisany jako klucz tłumaczony przy wyświetlaniu', function () {
    try {
        $stored = msg_key('auth.left', ['n' => 2]);
        assert_same('Pozostało prób: 2, potem blokada na 15 minut.', tr($stored));
        lang('en');
        assert_same('Attempts left: 2, then a 15-minute lockout.', tr($stored));
        assert_same('zwykły tekst', tr('zwykły tekst'));
        assert_same('', tr(null));
    } finally {
        lang('pl');
    }
});
