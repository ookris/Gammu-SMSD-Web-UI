<?php
// Symulator Gammu SMSD do pracy lokalnej bez modemu (rozdz. 9.2). Zachowuje się jak Gammu 1.42 wg rozdz. 3:
// czyta outbox tymi samymi warunkami, zapisuje sentitems, raporty doręczenia, inbox, phones, USSD, połączenia.
//
//   php tests/sim/gammu-sim.php init                     – var/dev/, bazy dev i test, config/config.php
//     (serwer bazy: SIM_DB_HOST, SIM_DB_PORT, SIM_DB_USER, SIM_DB_PASSWORD; lokalna MariaDB: tests/db/mariadb.sh start, port 3307)
//   php tests/sim/gammu-sim.php run                      – „demon” (osobny terminal)
//   php tests/sim/gammu-sim.php receive <numer> <treść> [--parts=N] [--incomplete] [--flash]
//   php tests/sim/gammu-sim.php external <numer> <treść> – wiadomość z „gammu-smsd-inject”
//   php tests/sim/gammu-sim.php call <numer>             – połączenie przychodzące
//   php tests/sim/gammu-sim.php status|reload|restart    – zamiast systemctl
//
// Numery kończące się na 000 – nieudana wysyłka (ponowienia, potem SendingError, kod 500);
// na 999 – raport DeliveryFailed (StatusError 70). USSD *100# – menu operatora (Status 3).
declare(strict_types=1);

const SIM_ROOT = __DIR__ . '/../..';
const DEV = SIM_ROOT . '/var/dev';

$cmd = $argv[1] ?? 'help';
$args = array_slice($argv, 2);
$opts = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $a;
    }
}

if ($cmd === 'init') {
    sim_init();
    exit(0);
}

require SIM_ROOT . '/src/bootstrap.php';
restore_error_handler();

switch ($cmd) {
    case 'status':
        $pid = sim_pid();
        echo $pid ? "active\n" : "inactive\n";
        exit($pid ? 0 : 3);
    case 'reload':
        $pid = sim_pid();
        if (!$pid) {
            echo "gammu-smsd (symulator) nie działa\n";
            exit(1);
        }
        posix_kill($pid, SIGHUP);
        exit(0);
    case 'restart':
        $pid = sim_pid();
        if (!$pid) {
            echo "gammu-smsd (symulator) nie działa – uruchom: php tests/sim/gammu-sim.php run\n";
            exit(1);
        }
        touch(DEV . '/sim.restart');
        posix_kill($pid, SIGHUP);
        exit(0);
    case 'receive':
    case 'call':
        if ($pos === [] || ($cmd === 'receive' && count($pos) < 2)) {
            fwrite(STDERR, "Brak argumentów\n");
            exit(1);
        }
        @mkdir(DEV . '/spool', 0777, true);
        $file = DEV . '/spool/' . microtime(true) . '-' . getmypid() . '.json';
        file_put_contents($file, json_encode(['type' => $cmd, 'number' => $pos[0], 'text' => $pos[1] ?? '', 'opts' => $opts]));
        echo sim_pid() ? "Przekazano do symulatora.\n" : "Zapisano – symulator przetworzy to po uruchomieniu (run).\n";
        exit(0);
    case 'external':
        if (count($pos) < 2) {
            fwrite(STDERR, "Brak argumentów\n");
            exit(1);
        }
        $gsm = SmsText::analyze($pos[1])['gsm'];
        Db::insert(Db::g() . '.outbox', [
            'DestinationNumber' => sim_number($pos[0]), 'TextDecoded' => $pos[1], 'Text' => '', 'UDH' => '',
            'Coding' => $gsm ? 'Default_No_Compression' : 'Unicode_No_Compression', 'Class' => -1, 'MultiPart' => 'false',
            'DeliveryReport' => 'yes', 'CreatorID' => 'inject', 'SendingDateTime' => now_db(),
        ]);
        echo "Dodano wiadomość zewnętrzną (CreatorID = inject).\n";
        exit(0);
    case 'run':
        sim_run();
        exit(0);
    default:
        echo file_get_contents(__FILE__, false, null, 0, 1200);
        exit(0);
}

// ---------------------------------------------------------------------------------------------------------

function sim_init(): void
{
    foreach ([DEV, DEV . '/backups', DEV . '/sessions', DEV . '/spool'] as $d) {
        @mkdir($d, 0777, true);
    }
    $user = getenv('SIM_DB_USER') ?: 'root';
    $pass = getenv('SIM_DB_PASSWORD') ?: '';
    $host = getenv('SIM_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SIM_DB_PORT') ?: '3306';
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents(SIM_ROOT . '/deploy/sql/gammu-mysql-17.sql');
    $schema = preg_replace('/^--.*$/m', '', $schema);
    foreach (['gammu_dev', 'gammu_test'] as $db) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db`");
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
            $pdo->exec($sql);
        }
    }
    foreach (['smsgui_dev', 'smsgui_test'] as $db) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci");
    }
    $dev = realpath(DEV);
    $root = realpath(SIM_ROOT);
    @touch("$dev/ttyUSB-sim");
    @touch("$dev/smsd.log");
    if (!is_file("$dev/gammu-smsdrc")) {
        file_put_contents("$dev/gammu-smsdrc", <<<INI
        # Konfiguracja Gammu SMSD – środowisko deweloperskie (symulator)
        [gammu]
        device = $dev/ttyUSB-sim
        connection = at

        [smsd]
        service = sql
        driver = native_mysql
        host = $host
        user = $user
        password = $pass
        database = gammu_dev
        phoneid = GSM1
        pin = 1234
        logfile = $dev/smsd.log
        debuglevel = 1
        deliveryreportdelay = 172800
        maxretries = 2
        retrytimeout = 15
        statusfrequency = 15

        INI);
    }
    $config = SIM_ROOT . '/config/config.php';
    if (!is_file($config)) {
        $php = PHP_BINARY;
        $sim = escapeshellarg($php) . ' ' . escapeshellarg("$root/tests/sim/gammu-sim.php");
        $export = var_export([
            'db' => ['dsn' => "mysql:host=$host;port=$port;dbname=smsgui_dev;charset=utf8mb4", 'user' => $user, 'password' => $pass],
            'gammu_db' => 'gammu_dev',
            'log_path' => "$dev/smsgui.log",
            'backup_dir' => "$dev/backups",
            'session_path' => "$dev/sessions",
            'gammu_conf' => "$dev/gammu-smsdrc",
            'blocklist_file' => "$dev/exclude-numbers.txt",
            'serial_dir' => $dev,
            'service' => ['status_cmd' => "$sim status", 'reload_cmd' => "$sim reload", 'restart_cmd' => "$sim restart"],
            'hook' => ['command' => escapeshellarg($php) . ' ' . escapeshellarg("$root/bin/smsgui") . ' hook call', 'cnf' => "$dev/hook.cnf"],
            'debug' => true,
        ], true);
        file_put_contents($config, "<?php\n// Środowisko deweloperskie – utworzone przez tests/sim/gammu-sim.php init\nreturn $export;\n");
    }
    if (!is_file("$dev/hook.cnf")) {
        file_put_contents("$dev/hook.cnf", "[client]\nuser = $user\npassword = $pass\nhost = $host\nport = $port\ndatabase = smsgui_dev\n");
    }
    echo "Gotowe: var/dev/, bazy smsgui_dev, gammu_dev (+ _test), config/config.php.\n";
    echo "Dalej: php bin/smsgui setup db && php bin/smsgui passwd admin\n";
}

function sim_pid(): int
{
    $pid = (int) @file_get_contents(DEV . '/sim.pid');
    return $pid > 0 && posix_kill($pid, 0) ? $pid : 0;
}

function sim_log(string $msg): void
{
    $path = GammuConf::load()?->get('smsd', 'logfile') ?? DEV . '/smsd.log';
    file_put_contents($path, date('D Y/m/d H:i:s') . ' gammu-smsd[' . getmypid() . ']: ' . $msg . PHP_EOL, FILE_APPEND);
}

/** Numer tak, jak podałby go modem: +48… (krajowy 9-cyfrowy dostaje +48). */
function sim_number(string $n): string
{
    $n = str_replace([' ', '-'], '', $n);
    if (preg_match('/^[0-9]{9}$/', $n)) {
        return '+48' . $n;
    }
    if (preg_match('/^48[0-9]{9}$/', $n)) {
        return '+' . $n;
    }
    return $n;
}

function sim_conf(string $key, string $default = ''): string
{
    return GammuConf::load()?->get('smsd', $key) ?? $default;
}

function sim_run(): void
{
    if (sim_pid()) {
        fwrite(STDERR, "Symulator już działa (PID " . sim_pid() . ")\n");
        exit(1);
    }
    file_put_contents(DEV . '/sim.pid', (string) getmypid());
    pcntl_async_signals(true);
    $reload = true;
    $stop = false;
    pcntl_signal(SIGHUP, function () use (&$reload) { $reload = true; });
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
    $g = Db::g();
    $exclude = [];
    $pending = []; // [czas, funkcja] – opóźnione raporty i odpowiedzi USSD
    $phoneId = 'GSM1';
    $lastStatus = 0;
    echo "Symulator Gammu SMSD działa (PID " . getmypid() . "). Ctrl+C – koniec.\n";

    while (!$stop) {
        if ($reload) {
            $reload = false;
            GammuConf::forget();
            if (is_file(DEV . '/sim.restart')) {
                @unlink(DEV . '/sim.restart');
                Db::exec("DELETE FROM $g.phones WHERE ID = ?", [$phoneId]);
                sim_log('Restart: stopping');
                echo "Restart…\n";
                sleep(3);
            }
            $phoneId = sim_conf('phoneid', 'GSM1');
            $file = sim_conf('excludenumbersfile');
            $exclude = $file !== '' && is_file($file) ? array_filter(array_map('trim', file($file))) : [];
            sim_log('Starting phone communication... (reload, excluded numbers: ' . count($exclude) . ')');
            sim_log('Database structures version: 17, SMSD current version: 17');
            $lastStatus = 0;
        }

        // Stan modemu co StatusFrequency (tabela phones)
        if (time() - $lastStatus >= min(10, (int) sim_conf('statusfrequency', '60'))) {
            $lastStatus = time();
            $signal = random_int(40, 90);
            Db::exec("INSERT INTO $g.phones (ID, IMEI, IMSI, NetCode, NetName, Client, Battery, `Signal`, Send, Receive, TimeOut)
                VALUES (?, '356938035643809', '260011234567890', '260 01', 'Symulator', 'Gammu 1.42.0 (symulator)', -1, ?, 'yes', 'yes', NOW() + INTERVAL 15 SECOND)
                ON DUPLICATE KEY UPDATE ID = VALUES(ID), `Signal` = VALUES(`Signal`), TimeOut = VALUES(TimeOut)", [$phoneId, $signal]);
            sim_log("Signal strength $signal %, network Symulator (260 01)");
        }

        // Opóźnione zdarzenia
        foreach ($pending as $k => [$due, $fn]) {
            if ($due <= microtime(true)) {
                unset($pending[$k]);
                $fn();
            }
        }

        // Kolejka modemu: odebrane SMS i połączenia
        foreach (glob(DEV . '/spool/*.json') ?: [] as $f) {
            $job = json_decode((string) file_get_contents($f), true);
            @unlink($f);
            if (!is_array($job)) {
                continue;
            }
            $number = sim_number((string) $job['number']);
            if ($job['type'] === 'call') {
                sim_call($number);
                continue;
            }
            if (in_array($number, $exclude, true)) {
                sim_log("Excluded $number from processing – deleting message");
                echo "SMS od $number pominięty (czarna lista).\n";
                continue;
            }
            sim_receive($number, (string) $job['text'], (array) $job['opts'], $phoneId);
        }

        // Wysyłka: te same warunki co Gammu (sql.c, find_outbox_sms_id)
        if (sim_conf('send', 'yes') !== 'no') {
            $row = Db::row("SELECT * FROM $g.outbox WHERE SendingDateTime <= NOW() AND (SendingTimeOut <= NOW() OR SendingTimeOut IS NULL)
                AND SendAfter <= CURTIME() AND SendBefore >= CURTIME() AND (SenderID IS NULL OR SenderID = '' OR SenderID = ?)
                ORDER BY Priority DESC, InsertIntoDB ASC, ID ASC LIMIT 1", [$phoneId]);
            if ($row !== null) {
                sim_send($row, $phoneId, $pending);
            }
        }
        usleep(1_000_000);
    }
    @unlink(DEV . '/sim.pid');
    Db::exec("DELETE FROM $g.phones WHERE ID = ?", [$phoneId]);
    echo "Koniec.\n";
}

function sim_send(array $row, string $phoneId, array &$pending): void
{
    $g = Db::g();
    $id = (int) $row['ID'];
    Db::exec("UPDATE $g.outbox SET SendingTimeOut = NOW() + INTERVAL 60 SECOND WHERE ID = ?", [$id]);
    $parts = array_merge([$row], Db::all("SELECT * FROM $g.outbox_multipart WHERE ID = ? ORDER BY SequencePosition", [$id]));
    $dest = (string) $row['DestinationNumber'];
    sim_log('Found 1 sms to "' . $dest . '" with text "' . mb_substr((string) $row['TextDecoded'], 0, 40) . '" parts ' . count($parts));

    if ((int) $row['Class'] === 127) {
        Db::insert("$g.sentitems", sim_sent($row, $row, 1, 'SendingOKNoReport', $phoneId));
        Db::exec("DELETE FROM $g.outbox WHERE ID = ?", [$id]);
        sim_log('Sending USSD ' . $dest);
        echo "USSD $dest\n";
        $pending[] = [microtime(true) + 3, function () use ($dest, $phoneId, $g) {
            [$text, $status] = match ($dest) {
                '*100#' => ["Wybierz opcję:\n1. Mój numer\n2. Kod PUK\n0. Wyjście", 3],
                '1' => ['Twój numer: +48 790 123 456', 2],
                '2' => ['Kod PUK: 12345678', 2],
                '*121#' => ['', -1],
                default => ['Twoje saldo: 12,34 zł. Ważne do 31.12.2026.', 2],
            };
            if ($status === -1) {
                sim_log('USSD timeout');
                return;
            }
            Db::insert("$g.inbox", ['SenderNumber' => '', 'TextDecoded' => $text, 'Text' => '', 'UDH' => '', 'Coding' => 'Unicode_No_Compression',
                'Class' => 127, 'RecipientID' => $phoneId, 'Status' => $status, 'ReceivingDateTime' => now_db()]);
            sim_log('Received USSD: ' . str_replace("\n", ' ', $text));
        }];
        return;
    }

    usleep(500_000);
    if (str_ends_with($dest, '000')) {
        $retries = (int) $row['Retries'];
        $max = (int) sim_conf('maxretries', '1');
        sim_log('Error sending SMS: SMSC reported error 500');
        if ($retries < $max) {
            Db::exec("UPDATE $g.outbox SET Retries = Retries + 1, StatusCode = 500, SendingTimeOut = NOW() + INTERVAL ? SECOND WHERE ID = ?",
                [(int) sim_conf('retrytimeout', '600'), $id]);
            sim_log('Failed to send message, retrying ' . ($retries + 1) . '/' . $max);
            echo "Błąd wysyłki do $dest – ponowienie " . ($retries + 1) . "/$max\n";
            return;
        }
        Db::tx(function () use ($parts, $row, $phoneId, $g, $id) {
            foreach ($parts as $i => $p) {
                Db::insert("$g.sentitems", sim_sent($row, $p, $i + 1, 'SendingError', $phoneId) + ['StatusCode' => 500]);
            }
            Db::exec("DELETE FROM $g.outbox WHERE ID = ?", [$id]);
            Db::exec("DELETE FROM $g.outbox_multipart WHERE ID = ?", [$id]);
        });
        echo "Wysyłka do $dest nieudana (SendingError)\n";
        return;
    }

    $report = $row['DeliveryReport'] === 'yes';
    Db::tx(function () use ($parts, $row, $phoneId, $g, $id, $report) {
        foreach ($parts as $i => $p) {
            Db::insert("$g.sentitems", sim_sent($row, $p, $i + 1, $report ? 'SendingOK' : 'SendingOKNoReport', $phoneId));
        }
        Db::exec("DELETE FROM $g.outbox WHERE ID = ?", [$id]);
        Db::exec("DELETE FROM $g.outbox_multipart WHERE ID = ?", [$id]);
        Db::exec("UPDATE $g.phones SET Sent = Sent + ? WHERE ID = ?", [count($parts), $phoneId]);
    });
    sim_log("Message sent to $dest (" . count($parts) . ' parts)');
    echo "Wysłano do $dest (" . count($parts) . " cz.)\n";
    if ($report) {
        $failed = str_ends_with($dest, '999');
        $pending[] = [microtime(true) + random_int(3, 6), function () use ($id, $failed, $g, $dest) {
            if ($failed) {
                Db::exec("UPDATE $g.sentitems SET Status = 'DeliveryFailed', StatusError = 70 WHERE ID = ?", [$id]);
            } else {
                Db::exec("UPDATE $g.sentitems SET Status = 'DeliveryOK', StatusError = 0, DeliveryDateTime = NOW() WHERE ID = ?", [$id]);
            }
            sim_log('Delivery report for ' . $dest . ': ' . ($failed ? 'failed (70)' : 'delivered'));
            echo 'Raport doręczenia ' . $dest . ': ' . ($failed ? 'niedoręczona' : 'doręczona') . "\n";
        }];
    }
}

function sim_sent(array $row, array $part, int $seq, string $status, string $phoneId): array
{
    return [
        'ID' => (int) $row['ID'], 'SequencePosition' => $seq, 'Status' => $status, 'StatusError' => -1,
        'TPMR' => random_int(0, 255), 'DestinationNumber' => $row['DestinationNumber'], 'TextDecoded' => (string) $part['TextDecoded'],
        'Text' => '', 'UDH' => (string) $part['UDH'], 'Coding' => $part['Coding'], 'Class' => (int) $part['Class'],
        'SenderID' => $phoneId, 'CreatorID' => $row['CreatorID'], 'SendingDateTime' => now_db(), 'StatusCode' => -1,
    ];
}

function sim_receive(string $number, string $text, array $opts, string $phoneId): void
{
    $g = Db::g();
    $parts = max(1, (int) ($opts['parts'] ?? 1));
    $incomplete = !empty($opts['incomplete']) && $parts > 1;
    $coding = SmsText::analyze($text)['gsm'] ? 'Default_No_Compression' : 'Unicode_No_Compression';
    $class = !empty($opts['flash']) ? 0 : -1;
    if ($parts === 1) {
        Db::insert("$g.inbox", ['SenderNumber' => $number, 'TextDecoded' => $text, 'Text' => '', 'UDH' => '', 'Coding' => $coding,
            'Class' => $class, 'RecipientID' => $phoneId, 'ReceivingDateTime' => now_db()]);
    } else {
        $chunks = mb_str_split($text, (int) ceil(mb_strlen($text) / $parts)) + array_fill(0, $parts, '');
        $ref = random_int(0, 255);
        $count = $incomplete ? $parts - 1 : $parts;
        for ($i = 1; $i <= $count; $i++) {
            // Gammu ≥ 1.40: sklejony tekst w pierwszym wierszu, kolejne puste; niekompletna – każda część osobno
            $body = $incomplete ? $chunks[$i - 1] : ($i === 1 ? $text : '');
            Db::insert("$g.inbox", ['SenderNumber' => $number, 'TextDecoded' => $body, 'Text' => '', 'Coding' => $coding, 'Class' => $class,
                'UDH' => sprintf('050003%02X%02X%02X', $ref, $parts, $i), 'RecipientID' => $phoneId, 'ReceivingDateTime' => now_db()]);
            usleep(300_000);
        }
    }
    Db::exec("UPDATE $g.phones SET Received = Received + ? WHERE ID = ?", [$parts, $phoneId]);
    sim_log("Received message from $number ($parts parts)");
    echo "Odebrano SMS od $number ($parts cz." . ($incomplete ? ', niekompletny' : '') . ")\n";
}

function sim_call(string $number): void
{
    sim_log('Incoming call from ' . ($number === '' ? '(hidden)' : $number));
    if (strtolower(sim_conf('hangupcalls', 'no')) !== 'yes') {
        echo "Połączenie od $number – HangupCalls wyłączone, Gammu nic nie robi.\n";
        return;
    }
    $cmd = sim_conf('runonincomingcall');
    sim_log('Hanging up call');
    if ($cmd !== '') {
        // Jak Gammu 1.42: sh -c "<polecenie> <numer>" – bez cytowania argumentu (rozdz. 3.11)
        sim_log("Starting run on incoming call: $cmd");
        exec('sh -c ' . escapeshellarg($cmd . ' ' . $number) . ' 2>&1', $out, $code);
        echo "Połączenie od $number – hook zakończony kodem $code\n" . implode("\n", $out) . ($out ? "\n" : '');
    }
}
