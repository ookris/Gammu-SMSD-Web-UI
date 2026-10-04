<?php
declare(strict_types=1);

/**
 * Połączenie PDO z MariaDB i migracje bazy panelu.
 * Jedno połączenie obsługuje obie bazy: tabele panelu bez prefiksu, tabele Gammu jako {g}.outbox
 * (nazwa bazy z konfiguracji gammu_db) – dzięki temu zapis do smsgui i gammu mieści się w jednej transakcji.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
            self::migrate();
        }
        return self::$pdo;
    }

    /** Nowe połączenie wg konfiguracji (bez migracji). */
    public static function connect(?string $dsn = null, ?string $user = null, ?string $password = null): PDO
    {
        $pdo = new PDO($dsn ?? (string) cfg('db.dsn'), $user ?? (string) cfg('db.user'), $password ?? (string) cfg('db.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Gammu zapisuje czas lokalny (NOW()) – połączenie dostaje strefę zgodną z PHP, żeby porównania czasów z tabelami Gammu się zgadzały.
        // Przesunięcie zamiast nazwy strefy: działa także bez załadowanych tabel stref w MariaDB.
        $pdo->exec("SET NAMES utf8mb4, time_zone = '" . date('P') . "', "
            . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** Podmiana połączenia (testy, ponowne połączenie procesu w tle). */
    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** Nazwa bazy Gammu w cudzysłowie, do zapytań: Db::g() . '.outbox'. */
    public static function g(): string
    {
        return '`' . str_replace('`', '', (string) cfg('gammu_db', 'gammu')) . '`';
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare(str_replace('{g}', self::g(), $sql));
        $st->execute(array_values($params));
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Pierwsza kolumna wszystkich wierszy. */
    public static function col(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Liczba zmienionych wierszy. */
    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_map(static fn ($c) => "`$c`", array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        self::q("INSERT INTO $table ($cols) VALUES ($marks)", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        return self::exec("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params]);
    }

    /** Znaczniki ?,?,? dla IN (…). */
    public static function in(array $values): string
    {
        return $values === [] ? 'NULL' : implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Transakcja: wynik funkcji albo wycofanie i wyjątek dalej. Wywołanie wewnątrz trwającej transakcji dołącza do niej
     * (np. wysyłka do wielu obejmuje wszystkie Outbox::create() jedną transakcją).
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** GET_LOCK – blokada wspólna dla panelu, procesu w tle i CLI. */
    public static function lock(string $name, int $timeout = 0): bool
    {
        return (int) self::val('SELECT GET_LOCK(?, ?)', [$name, $timeout]) === 1;
    }

    public static function unlock(string $name): void
    {
        self::val('SELECT RELEASE_LOCK(?)', [$name]);
    }

    // ---------- Migracje ----------

    public static function schemaVersion(): int
    {
        return (int) self::val('SELECT version FROM schema_version LIMIT 1');
    }

    /** Wykonuje brakujące migracje; DDL zatwierdza transakcję sam, więc każda jest idempotentna. */
    public static function migrate(): void
    {
        $pdo = self::$pdo ?? throw new LogicException('Brak połączenia');
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INT NOT NULL) ENGINE=InnoDB');
        $migrations = self::migrations();
        $current = (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        if ($current >= array_key_last($migrations)) {
            return;
        }
        if ((int) $pdo->query("SELECT GET_LOCK('smsgui-migrate', 30)")->fetchColumn() !== 1) {
            throw new RuntimeException('Nie udało się uzyskać blokady migracji');
        }
        try {
            $current = (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
            foreach ($migrations as $version => $statements) {
                if ($version <= $current) {
                    continue;
                }
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->exec('DELETE FROM schema_version');
                $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
                app_log('info', "migracja bazy panelu do wersji $version");
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('smsgui-migrate')");
        }
    }

    /** @return array<int, list<string>> */
    private static function migrations(): array
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci';
        return [
            1 => [
                "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(64) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    remember_secret CHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL,
                    last_login_at DATETIME NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS contacts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    phone VARCHAR(32) NOT NULL UNIQUE,
                    note TEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY contacts_name (name)
                ) $t",
                "CREATE TABLE IF NOT EXISTS `groups` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS contact_group_members (
                    contact_id INT NOT NULL,
                    group_id INT NOT NULL,
                    PRIMARY KEY (contact_id, group_id),
                    KEY members_group (group_id),
                    CONSTRAINT members_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
                    CONSTRAINT members_group_fk FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE CASCADE
                ) $t",
                "CREATE TABLE IF NOT EXISTS messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    direction ENUM('in','out') NOT NULL,
                    phone VARCHAR(32) NOT NULL,
                    body TEXT NOT NULL,
                    encoding ENUM('GSM','UCS2','8bit') NOT NULL DEFAULT 'GSM',
                    parts TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    status VARCHAR(16) NOT NULL,
                    incomplete TINYINT(1) NOT NULL DEFAULT 0,
                    flash TINYINT(1) NOT NULL DEFAULT 0,
                    report TINYINT(1) NOT NULL DEFAULT 0,
                    priority TINYINT NOT NULL DEFAULT 0,
                    send_window TINYINT(1) NOT NULL DEFAULT 0,
                    source ENUM('gui','external','api','autoreply','notify') NOT NULL DEFAULT 'gui',
                    api_token_id INT NULL,
                    batch_id CHAR(16) NULL,
                    gammu_id INT UNSIGNED NULL,
                    udh_ref TINYINT UNSIGNED NULL,
                    modem VARCHAR(64) NULL,
                    modem_requested VARCHAR(64) NULL,
                    retries TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    status_code INT NULL,
                    error VARCHAR(255) NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 1,
                    scheduled_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    sent_at DATETIME NULL,
                    received_at DATETIME NULL,
                    delivered_at DATETIME NULL,
                    updated_at DATETIME NOT NULL,
                    KEY messages_thread (phone, id),
                    KEY messages_status (direction, status),
                    KEY messages_gammu (direction, gammu_id),
                    KEY messages_updated (updated_at),
                    KEY messages_batch (batch_id)
                ) $t",
                "CREATE TABLE IF NOT EXISTS calls (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    phone VARCHAR(32) NOT NULL DEFAULT '',
                    raw_number VARCHAR(40) NOT NULL DEFAULT '',
                    modem VARCHAR(64) NOT NULL DEFAULT '',
                    received_at DATETIME NOT NULL,
                    processed TINYINT(1) NOT NULL DEFAULT 0,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    KEY calls_phone (phone, received_at),
                    KEY calls_processed (processed)
                ) $t",
                "CREATE TABLE IF NOT EXISTS ussd_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    modem VARCHAR(64) NULL,
                    code VARCHAR(64) NOT NULL,
                    parent_id INT NULL,
                    gammu_id INT UNSIGNED NULL,
                    status ENUM('queued','sent','answered','timeout','failed') NOT NULL DEFAULT 'queued',
                    response TEXT NULL,
                    session_status TINYINT NULL,
                    purpose ENUM('manual','balance') NOT NULL DEFAULT 'manual',
                    created_at DATETIME NOT NULL,
                    sent_at DATETIME NULL,
                    answered_at DATETIME NULL,
                    KEY ussd_status (status)
                ) $t",
                "CREATE TABLE IF NOT EXISTS blocked_numbers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    phone VARCHAR(32) NOT NULL UNIQUE,
                    note VARCHAR(255) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS modem_status (
                    modem VARCHAR(64) PRIMARY KEY,
                    imei VARCHAR(35) NOT NULL DEFAULT '',
                    imsi VARCHAR(35) NOT NULL DEFAULT '',
                    signal_pct TINYINT NOT NULL DEFAULT -1,
                    battery_pct TINYINT NOT NULL DEFAULT -1,
                    net_code VARCHAR(35) NOT NULL DEFAULT '',
                    net_name VARCHAR(35) NOT NULL DEFAULT '',
                    sent INT NOT NULL DEFAULT 0,
                    received INT NOT NULL DEFAULT 0,
                    client VARCHAR(255) NOT NULL DEFAULT '',
                    balance VARCHAR(255) NULL,
                    balance_value DECIMAL(10,2) NULL,
                    balance_at DATETIME NULL,
                    gammu_updated_at DATETIME NULL,
                    updated_at DATETIME NOT NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    body TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS settings (
                    `key` VARCHAR(64) PRIMARY KEY,
                    `value` TEXT NOT NULL
                ) $t",
                "CREATE TABLE IF NOT EXISTS login_attempts (
                    ip VARCHAR(45) NOT NULL,
                    attempted_at DATETIME NOT NULL,
                    KEY attempts_ip (ip, attempted_at)
                ) $t",
            ],
        ];
    }
}
