<?php
// Runner testów bez zależności: php tests/run.php [filtr]
// Bazy testowe smsgui_test i gammu_test (tworzy je tests/sim/gammu-sim.php init) są czyszczone przed testami.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$base = app_config();
$tmp = APP_ROOT . '/var/test';
@mkdir($tmp, 0777, true);
$dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . (getenv('SMSGUI_TEST_DB') ?: 'smsgui_test'), (string) $base['db']['dsn']);
app_config(array_replace_recursive($base, [
    'db' => ['dsn' => $dsn],
    'gammu_db' => getenv('SMSGUI_TEST_GAMMU_DB') ?: 'gammu_test',
    'log_path' => "$tmp/smsgui.log",
    'backup_dir' => "$tmp/backups",
    'session_path' => "$tmp/sessions",
    'gammu_conf' => "$tmp/gammu-smsdrc",
    'blocklist_file' => "$tmp/exclude-numbers.txt",
    'hook' => ['cnf' => "$tmp/hook.cnf"], // brak pliku = baza testowa, nigdy produkcyjne /etc/smsgui/hook.cnf
    'service' => ['status_cmd' => 'echo active', 'reload_cmd' => 'true', 'restart_cmd' => 'true'],
    'default_country_code' => '48',
    'national_number_length' => 9,
    'debug' => true,
]));

$tests = [];
$current = '';
function test(string $name, callable $fn): void
{
    global $tests, $current;
    $tests[] = [$current . ': ' . $name, $fn];
}

final class AssertionFailed extends Exception
{
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg !== '' ? "$msg\n" : '') . '  oczekiwano: ' . var_export($expected, true) . "\n  otrzymano:  " . var_export($actual, true));
    }
}

function assert_true(mixed $value, string $msg = 'oczekiwano true'): void
{
    if ($value !== true) {
        throw new AssertionFailed($msg . ' (otrzymano ' . var_export($value, true) . ')');
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg !== '' ? "$msg\n" : '') . "  brak „{$needle}” w: " . mb_substr($haystack, 0, 400));
    }
}

/** Czyste tabele w bazach testowych (wywoływane przez testy korzystające z bazy). */
function reset_db(): void
{
    $pdo = Db::connect();
    Db::set($pdo);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (['inbox', 'outbox', 'outbox_multipart', 'sentitems', 'phones'] as $t) {
        $pdo->exec('DELETE FROM ' . Db::g() . ".$t");
    }
    Db::set(null);
    Db::pdo();
    Settings::forget();
    Status::forget();
    GammuConf::forget();
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $current = basename($file, 'Test.php');
    require $file;
}

$passed = $failed = 0;
foreach ($tests as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "✔ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "✘ $name\n" . ($e instanceof AssertionFailed ? $e->getMessage() : $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . "\n";
    }
}
echo "\n" . ($failed === 0 ? 'OK' : 'BŁĘDY') . ": $passed zaliczonych, $failed nieudanych\n";
exit($failed === 0 ? 0 : 1);
