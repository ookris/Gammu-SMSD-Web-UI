<?php
declare(strict_types=1);

/**
 * Parser i edytor /etc/gammu-smsdrc (rozdz. 3.8). Plik INI przetwarzany linia po linii:
 * zmiana parametru dotyka tylko jego linii, komentarze (całe linie z # lub ;) i kolejność zostają.
 * Nazwy sekcji i kluczy bez rozróżniania wielkości liter; # w środku wartości jest częścią wartości.
 */
final class GammuConf
{
    public const MASK = '********';
    /** Wartości maskowane w przeglądarce (edytor, różnice, podgląd kopii). */
    public const SECRET_KEYS = ['password', 'pin'];
    /** Parametry ustawiane przez instalator lub funkcje panelu – nie ma ich w formularzu. */
    public const MANAGED = ['service', 'driver', 'host', 'user', 'password', 'database', 'sql', 'runonincomingcall',
        'excludenumbersfile', 'includenumbersfile', 'hangupcalls'];

    /** @var list<string> */
    private array $lines;
    private bool $finalNewline;

    private static array $loaded = [];

    private function __construct(string $text)
    {
        $text = str_replace("\r\n", "\n", $text);
        $this->finalNewline = $text === '' || str_ends_with($text, "\n");
        $this->lines = $text === '' ? [] : explode("\n", $this->finalNewline ? substr($text, 0, -1) : $text);
    }

    public static function parse(string $text): self
    {
        return new self($text);
    }

    public static function path(): string
    {
        return (string) cfg('gammu_conf');
    }

    /** Plik z konfiguracji (null, gdy nie da się go odczytać). */
    public static function load(bool $fresh = false): ?self
    {
        $path = self::path();
        if ($fresh || !array_key_exists($path, self::$loaded)) {
            $text = is_readable($path) ? @file_get_contents($path) : false;
            self::$loaded[$path] = $text === false ? null : new self($text);
        }
        return self::$loaded[$path];
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }

    /** StatusFrequency z konfiguracji (domyślnie 60 s) – do wykrywania niedostępnego modemu. */
    public static function statusFrequency(): int
    {
        $v = self::load()?->get('smsd', 'statusfrequency');
        return $v !== null && ctype_digit($v) ? (int) $v : 60;
    }

    public function text(): string
    {
        return implode("\n", $this->lines) . ($this->finalNewline && $this->lines !== [] ? "\n" : '');
    }

    /** Opis linii: typ section|kv|comment|blank|invalid, sekcja, klucz (małe litery), wartość. */
    public static function parseLine(string $line): array
    {
        if (trim($line) === '') {
            return ['type' => 'blank'];
        }
        if (preg_match('/^\s*[#;]/', $line)) {
            return ['type' => 'comment'];
        }
        if (preg_match('/^\s*\[([^\]]*)\]\s*$/', $line, $m)) {
            return ['type' => 'section', 'section' => strtolower(trim($m[1]))];
        }
        if (preg_match('/^\s*([^=]*?)\s*=\s*(.*?)\s*$/', $line, $m) && $m[1] !== '') {
            return ['type' => 'kv', 'key' => strtolower($m[1]), 'value' => $m[2]];
        }
        return ['type' => 'invalid'];
    }

    /** Wszystkie linie z sekcją: [nr linii (od 1), typ, sekcja, klucz, wartość, tekst]. */
    public function entries(): array
    {
        $out = [];
        $section = '';
        foreach ($this->lines as $i => $line) {
            $p = self::parseLine($line);
            if ($p['type'] === 'section') {
                $section = $p['section'];
            }
            $out[] = $p + ['line' => $i + 1, 'section' => $section, 'key' => null, 'value' => null, 'text' => $line];
        }
        return $out;
    }

    public function sections(): array
    {
        return array_values(array_unique(array_column(array_filter($this->entries(), static fn ($e) => $e['type'] === 'section'), 'section')));
    }

    public function get(string $section, string $key): ?string
    {
        $section = strtolower($section);
        $key = strtolower($key);
        foreach ($this->entries() as $e) {
            if ($e['type'] === 'kv' && $e['section'] === $section && $e['key'] === $key) {
                return $e['value'];
            }
        }
        return null;
    }

    /** Parametry sekcji jako klucz => wartość (pierwsze wystąpienie). */
    public function section(string $section): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            if ($e['type'] === 'kv' && $e['section'] === strtolower($section)) {
                $out[$e['key']] ??= $e['value'];
            }
        }
        return $out;
    }

    /** Zmiana parametru: podmiana w tej samej linii, dopisanie za ostatnim parametrem sekcji, nowa sekcja; null/'' = usunięcie. */
    public function set(string $section, string $key, ?string $value): void
    {
        $section = strtolower($section);
        $key = strtolower($key);
        $value = $value === null ? null : trim(str_replace(["\r", "\n"], ' ', $value));
        $start = null;
        $end = count($this->lines);
        $lastKv = null;
        $found = [];
        $current = '';
        foreach ($this->lines as $i => $line) {
            $p = self::parseLine($line);
            if ($p['type'] === 'section') {
                if ($start !== null && $current === $section && $p['section'] !== $section) {
                    $end = $i;
                    break;
                }
                $current = $p['section'];
                if ($current === $section) {
                    $start ??= $i;
                }
                continue;
            }
            if ($current === $section && $start !== null) {
                if ($p['type'] === 'kv') {
                    $lastKv = $i;
                    if ($p['key'] === $key) {
                        $found[] = $i;
                    }
                }
            }
        }
        if ($found !== []) {
            if ($value === null || $value === '') {
                foreach (array_reverse($found) as $i) {
                    array_splice($this->lines, $i, 1);
                }
                return;
            }
            $i = $found[0];
            $this->lines[$i] = preg_replace_callback('/^(\s*[^=]*?\s*=\s*)(.*?)(\s*)$/', static fn ($m) => $m[1] . $value . $m[3], $this->lines[$i], 1);
            return;
        }
        if ($value === null || $value === '') {
            return;
        }
        $new = $key . ' = ' . $value;
        if ($start === null) {
            if ($this->lines !== [] && trim((string) end($this->lines)) !== '') {
                $this->lines[] = '';
            }
            $this->lines[] = '[' . $section . ']';
            $this->lines[] = $new;
            $this->finalNewline = true;
            return;
        }
        array_splice($this->lines, ($lastKv ?? $start) + 1, 0, [$new]);
    }

    // ---------- Maskowanie haseł (rozdz. 3.8, 5.1) ----------

    /** Tekst z zamaskowanym hasłem do bazy i PIN-em. */
    public static function mask(string $text): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        foreach ($lines as &$line) {
            $p = self::parseLine($line);
            if ($p['type'] === 'kv' && in_array($p['key'], self::SECRET_KEYS, true) && $p['value'] !== '') {
                $line = preg_replace('/^(\s*[^=]*?\s*=\s*)(.*?)(\s*)$/', '${1}' . self::MASK . '${3}', $line, 1);
            }
        }
        return implode("\n", $lines);
    }

    /** Niezmienione maskowane pole zachowuje oryginalną wartość (dopasowanie po sekcji i kluczu). */
    public static function unmask(string $edited, string $original): string
    {
        $orig = self::parse($original);
        $lines = explode("\n", str_replace("\r\n", "\n", $edited));
        $section = '';
        foreach ($lines as &$line) {
            $p = self::parseLine($line);
            if ($p['type'] === 'section') {
                $section = $p['section'];
            } elseif ($p['type'] === 'kv' && $p['value'] === self::MASK && in_array($p['key'], self::SECRET_KEYS, true)) {
                $real = $orig->get($section, $p['key']);
                if ($real !== null) {
                    $line = preg_replace_callback('/^(\s*[^=]*?\s*=\s*)(.*?)(\s*)$/', static fn ($m) => $m[1] . $real . $m[3], $line, 1);
                }
            }
        }
        return implode("\n", $lines);
    }

    // ---------- Walidacja (rozdz. 2.10b) ----------

    /** Lista [poziom ok|warn|err, tekst HTML-bezpieczny po e()] – $original do wykrycia zmian parametrów zarządzanych. */
    public function validate(?self $original = null): array
    {
        $out = [];
        $entries = $this->entries();
        $sections = $this->sections();
        $missing = array_diff(['gammu', 'smsd'], $sections);
        $out[] = $missing === [] ? ['ok', 'Sekcje [gammu] i [smsd] obecne']
            : ['err', 'Brak sekcji: [' . implode('], [', $missing) . ']'];

        $service = strtolower((string) $this->get('smsd', 'service'));
        $db = $this->get('smsd', 'database');
        if ($service !== 'sql') {
            $out[] = ['err', 'service = ' . ($service ?: '(brak)') . ' – panel wymaga service = sql'];
        } elseif ($db !== (string) cfg('gammu_db')) {
            $out[] = ['err', 'database = ' . ($db ?? '(brak)') . ' – panel używa bazy ' . cfg('gammu_db')];
        } else {
            $out[] = ['ok', 'service = sql, baza ' . $db . ' zgodna z panelem'];
        }

        $device = $this->get('gammu', 'device');
        if ($device === null || $device === '') {
            $out[] = ['err', 'Brak parametru device w sekcji [gammu]'];
        } else {
            $out[] = file_exists($device) ? ['ok', 'Port modemu istnieje'] : ['warn', 'Port modemu ' . $device . ' nie istnieje'];
        }

        $drd = $this->get('smsd', 'deliveryreportdelay');
        if ($drd === null || (int) $drd < 3600) {
            $out[] = ['warn', 'deliveryreportdelay = ' . ($drd ?? '600 (domyślnie)') . ' – zalecane co najmniej 3600, najlepiej 172800'];
        }

        $invalid = array_filter($entries, static fn ($e) => $e['type'] === 'invalid');
        $out[] = $invalid === [] ? ['ok', 'Wszystkie linie zrozumiałe']
            : ['err', 'Niezrozumiałe linie: ' . implode(', ', array_column($invalid, 'line'))];

        foreach (['includenumbersfile', 'includesmsc'] as $k) {
            if ($this->get('smsd', $k) !== null) {
                $out[] = ['warn', $k . ' ustawiony – lista dozwolonych wyłącza czarną listę'];
            }
        }
        if (in_array('include_numbers', $sections, true)) {
            $out[] = ['warn', 'Sekcja [include_numbers] – lista dozwolonych wyłącza czarną listę'];
        }
        foreach ($this->section('smsd') as $k => $v) {
            if (str_starts_with($k, 'runon') && $k !== 'runonincomingcall') {
                $out[] = ['warn', $k . ' = ' . $v . ' – polecenie uruchamiane przez Gammu (jako root)'];
            }
        }
        if ($original !== null) {
            $changed = [];
            foreach (self::MANAGED as $k) {
                if ($original->get('smsd', $k) !== $this->get('smsd', $k)) {
                    $changed[] = $k;
                }
            }
            $out[] = $changed === [] ? ['ok', 'Parametry zarządzane przez panel bez zmian']
                : ['warn', 'Zmieniasz parametry zarządzane przez panel: ' . implode(', ', $changed)];
        }
        return $out;
    }

    public static function hasErrors(array $validation): bool
    {
        return in_array('err', array_column($validation, 0), true);
    }

    // ---------- Zapis i kopie zapasowe (rozdz. 2.10c, 3.8) ----------

    public static function backupDir(): string
    {
        return rtrim((string) cfg('backup_dir'), '/');
    }

    /**
     * Odcisk treści pliku – formularze niosą go od otwarcia do zapisu (wykrycie zmian z innej sesji).
     * Końce linii ujednolicone: formularz liczy go z tekstu po parserze (LF), zapis – z surowych bajtów pliku (być może CRLF).
     */
    public static function fingerprint(string $text): string
    {
        return md5(str_replace("\r\n", "\n", $text));
    }

    /**
     * Zapis „w miejscu” z blokadą – www-data nie może tworzyć plików w /etc. Pod blokadą: ponowny odczyt, porównanie
     * z oczekiwanym odciskiem ($expected), kopia zapasowa, zapis. Inna zmiana w międzyczasie → ConfStaleException.
     */
    public static function save(string $text, string $note, ?string $expected = null): string
    {
        $path = self::path();
        $fh = @fopen($path, 'r+'); // bez tworzenia: brakujący plik zostaje brakujący i jest zgłaszany jako nieczytelny
        if ($fh === false) {
            throw new RuntimeException(is_readable($path) ? "Brak prawa zapisu do $path" : "Nie można odczytać $path");
        }
        try {
            flock($fh, LOCK_EX);
            $current = (string) stream_get_contents($fh);
            if ($expected !== null && !hash_equals($expected, self::fingerprint($current))) {
                throw new ConfStaleException("$path zmienił się od otwarcia formularza");
            }
            $backup = self::backup($current, $note);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $text);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        self::forget();
        app_log('info', "zapis $path ($note), kopia $backup");
        return $backup;
    }

    public static function backup(string $content, string $note, string $prefix = 'gammu-smsdrc'): string
    {
        $dir = self::backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new RuntimeException("Nie można utworzyć katalogu kopii $dir");
        }
        $name = $prefix . '.' . date('Ymd-His');
        for ($i = 1; is_file("$dir/$name"); $i++) {
            $name = $prefix . '.' . date('Ymd-His') . '-' . $i;
        }
        self::createFile("$dir/$name", $content, 0640);
        $notes = Settings::json('backup_notes');
        $notes[$name] = mb_substr($note, 0, 200);
        // Limit kopii (ustawienie backup_keep)
        $keep = max(1, Settings::int('backup_keep'));
        $all = self::backups($prefix);
        foreach (array_slice($all, $keep) as $old) {
            @unlink("$dir/{$old['name']}");
            unset($notes[$old['name']]);
        }
        Settings::set('backup_notes', $notes);
        return $name;
    }

    /**
     * Nowy plik tworzony od razu z prawami $mode (umask na czas otwarcia) i wyłącznie, gdy jeszcze nie istnieje –
     * kopie zawierają hasło do bazy i PIN, nie mogą choćby na chwilę być czytelne dla innych ani nadpisać istniejącej kopii.
     */
    public static function createFile(string $path, string $content, int $mode = 0600): void
    {
        $old = umask(0777 & ~$mode);
        try {
            $fh = @fopen($path, 'x');
        } finally {
            umask($old);
        }
        if ($fh === false) {
            throw new RuntimeException("Nie można utworzyć pliku $path" . (file_exists($path) ? ' (już istnieje)' : ''));
        }
        $ok = true;
        for ($done = 0, $len = strlen($content); $done < $len; $done += $n) {
            $n = fwrite($fh, substr($content, $done));
            if ($n === false || $n === 0) {
                $ok = false;
                break;
            }
        }
        $ok = fflush($fh) && $ok;
        fclose($fh);
        if (!$ok) {
            @unlink($path); // niepełna kopia nie może zostać jako „poprawna”
            throw new RuntimeException("Nie można zapisać pliku $path");
        }
        @chmod($path, $mode);
    }

    /** Kopie od najnowszej: [name, time, size, note]. */
    public static function backups(string $prefix = 'gammu-smsdrc'): array
    {
        $dir = self::backupDir();
        $notes = Settings::json('backup_notes');
        $out = [];
        foreach (glob($dir . '/' . $prefix . '.*') ?: [] as $file) {
            $name = basename($file);
            if (!self::validBackupName($name, $prefix)) {
                continue;
            }
            $out[] = ['name' => $name, 'time' => filemtime($file), 'size' => filesize($file), 'note' => $notes[$name] ?? ''];
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Nazwa kopii z formularza – tylko wzorzec, bez ścieżek (rozdz. 5.1). */
    public static function validBackupName(string $name, string $prefix = 'gammu-smsdrc'): bool
    {
        return (bool) preg_match('/^' . preg_quote($prefix, '/') . '\.\d{8}-\d{6}(-\d+)?$/', $name);
    }

    public static function readBackup(string $name): ?string
    {
        if (!self::validBackupName($name)) {
            return null;
        }
        $text = @file_get_contents(self::backupDir() . '/' . $name);
        return $text === false ? null : $text;
    }
}
