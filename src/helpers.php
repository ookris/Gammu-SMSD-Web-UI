<?php
// Funkcje pomocnicze używane w całym panelu (rozdz. 9.1 – konwencje kodu).
declare(strict_types=1);

/** Wartość konfiguracji, klucze z kropką: cfg('db.dsn'). */
function cfg(string $key, mixed $default = null): mixed
{
    $value = app_config();
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Każde wyjście do HTML przechodzi przez e(). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Adres strony panelu (front controller ?p=). */
function url(string $page = '', array $params = []): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== false);
    if ($page !== '' && $page !== 'dashboard') {
        $params = ['p' => $page] + $params;
    }
    return $params === [] ? './' : '?' . http_build_query($params);
}

/** Adres pliku z public/assets z wersją (unieważnia pamięć podręczną przeglądarki po aktualizacji). */
function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . $path;
    return 'assets/' . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Tekst interfejsu z resources/lang/pl.php; {zmienne} podstawiane z $vars. */
function t(string $key, array $vars = []): string
{
    static $lang = null;
    $lang ??= require APP_ROOT . '/resources/lang/pl.php';
    $text = $lang[$key] ?? $key;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

/** Odmiana liczebników: plural(3, 'znak', 'znaki', 'znaków'). */
function plural(int $n, string $one, string $few, string $many): string
{
    if ($n === 1) {
        return $one;
    }
    $d = $n % 10;
    $t = $n % 100;
    return $d >= 2 && $d <= 4 && ($t < 12 || $t > 14) ? $few : $many;
}

/** Ikona Solar wstawiana inline (zgodnie z CSP, bez <img>). */
function icon(string $name, string $class = ''): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $file = APP_ROOT . '/resources/icons/' . basename($name) . '.svg';
        $svg = is_file($file) ? file_get_contents($file) : '<svg viewBox="0 0 24 24"></svg>';
        $cache[$name] = preg_replace('~^<svg[^>]*>~', '', trim($svg));
    }
    $cls = trim('icon ' . $class);
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true">' . $cache[$name];
}

// ---------- Żądanie i odpowiedź ----------

function is_htmx(): bool
{
    return isset($_SERVER['HTTP_HX_REQUEST']) && !isset($_SERVER['HTTP_HX_BOOSTED']);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Parametr GET/POST jako tekst (przycięty). */
function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Parametr GET/POST jako tablica tekstów (np. ids[]). */
function input_list(string $key): array
{
    $v = $_POST[$key] ?? $_GET[$key] ?? [];
    return is_array($v) ? array_values(array_filter(array_map(static fn ($x) => is_string($x) ? trim($x) : '', $v), 'strlen')) : [];
}

/** Lista identyfikatorów liczbowych z ids[] (akcje zbiorcze). */
function input_ids(string $key = 'ids'): array
{
    return array_values(array_unique(array_filter(array_map('intval', input_list($key)), static fn ($id) => $id > 0)));
}

function request_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function redirect(string $to): never
{
    if (is_htmx()) {
        header('HX-Redirect: ' . $to);
    } else {
        header('Location: ' . $to, true, 303);
    }
    exit;
}

/** Powrót na stronę, z której przyszło żądanie (tylko w obrębie panelu). */
function back(string $fallback = './'): never
{
    $ref = input('back');
    if ($ref !== '' && preg_match('~^(\./|\?p=[a-z0-9-]+)~', $ref)) {
        redirect($ref);
    }
    redirect($fallback);
}

/** Bieżący adres (do pola back w formularzach). */
function current_url(): string
{
    $q = $_SERVER['QUERY_STRING'] ?? '';
    return $q === '' ? './' : '?' . $q;
}

/** Widok bez układu (np. fragment dla htmx). */
function view(string $name, array $data = []): string
{
    $file = APP_ROOT . '/views/' . $name . '.php';
    if (!preg_match('~^[a-z0-9/_-]+$~', $name) || !is_file($file)) {
        throw new RuntimeException("Brak widoku: $name");
    }
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require $file;
        return (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** Strona w układzie panelu; żądanie htmx dostaje sam fragment (jedna logika dla obu). */
function render(string $name, array $data = []): never
{
    $content = view($name, $data);
    if (is_htmx()) {
        echo $content;
        exit;
    }
    echo view('layout', $data + ['content' => $content]);
    exit;
}

// ---------- CSRF i komunikaty ----------

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? $_GET['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
}

/** Komunikat po przekierowaniu: typ ok|err|warn|info, pogrubiony tytuł i opcjonalny opis. */
function flash(string $type, string $title, string $detail = ''): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'title' => $title, 'detail' => $detail];
}

function take_flashes(): array
{
    $list = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $list;
}

/** Komunikaty jako HTML (widok wstawia je pod nagłówkiem strony; resztę pokaże układ). */
function flashes_html(): string
{
    $html = '';
    foreach (take_flashes() as $f) {
        $html .= alert($f['type'], $f['title'], $f['detail']);
    }
    return $html === '' ? '' : '<div class="stack-sm flashes">' . $html . '</div>';
}

/** Komunikat w stylu panelu; $detail i $linkHtml to już bezpieczny HTML lub tekst (escapowany). */
function alert(string $type, string $title, string $detail = '', string $linkHtml = '', bool $tight = true): string
{
    $icons = ['ok' => 'check-circle', 'err' => 'danger-circle', 'warn' => 'danger-triangle', 'info' => 'info-circle'];
    $role = in_array($type, ['err', 'warn'], true) ? 'alert' : 'status';
    return '<div class="alert alert-' . e($type) . ($tight ? ' tight' : '') . '" role="' . $role . '">' . icon($icons[$type] ?? 'info-circle')
        . '<div>' . ($title !== '' ? '<strong>' . e($title) . '</strong> ' : '') . e($detail) . '</div>' . $linkHtml . '</div>';
}

// ---------- Daty ----------

/** Data RRRR-MM-DD z formularza filtrów: istniejąca (round-trip) i w zakresie DATETIME; inaczej null. */
function valid_date(string $value): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($d === false || $d->format('Y-m-d') !== $value || (int) $d->format('Y') < 1000) {
        return null;
    }
    return $value;
}

/** Filtry dat: niepoprawne wartości usuwane, żeby widok nie pokazywał nieaktywnego filtra. */
function clean_dates(array $f, array $keys): array
{
    foreach ($keys as $k) {
        $f[$k] = valid_date((string) ($f[$k] ?? '')) ?? '';
    }
    return $f;
}

/** Data z bazy (czas lokalny) jako znacznik czasu; null dla pustej. */
function ts(?string $dt): ?int
{
    if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) {
        return null;
    }
    $t = strtotime($dt);
    return $t === false ? null : $t;
}

function now_db(int $offset = 0): string
{
    return date('Y-m-d H:i:s', time() + $offset);
}

/** „dziś 11:41”, „wczoraj 17:20”, „02.10 16:45”, „02.10.2025 16:45”. */
function fmt_when(?string $dt): string
{
    $t = ts($dt);
    if ($t === null) {
        return '—';
    }
    $day = date('Y-m-d', $t);
    return match (true) {
        $day === date('Y-m-d') => 'dziś ' . date('H:i', $t),
        $day === date('Y-m-d', strtotime('-1 day')) => 'wczoraj ' . date('H:i', $t),
        $day === date('Y-m-d', strtotime('+1 day')) => 'jutro ' . date('H:i', $t),
        date('Y', $t) === date('Y') => date('d.m H:i', $t),
        default => date('d.m.Y H:i', $t),
    };
}

/** Krótka data na listach: „11:41”, „wczoraj”, „pt.”, „28.09”. */
function fmt_short(?string $dt): string
{
    $t = ts($dt);
    if ($t === null) {
        return '—';
    }
    $days = (int) ((strtotime('today') - strtotime(date('Y-m-d', $t))) / 86400);
    return match (true) {
        $days <= 0 => date('H:i', $t),
        $days === 1 => 'wczoraj',
        $days < 7 => ['nd.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'][(int) date('w', $t)],
        date('Y', $t) === date('Y') => date('d.m', $t),
        default => date('d.m.Y', $t),
    };
}

/** „12 s temu”, „4 min temu”, „2 godz. temu”, dalej data. */
function fmt_ago(?string $dt): string
{
    $t = ts($dt);
    if ($t === null) {
        return 'nigdy';
    }
    $s = max(0, time() - $t);
    return match (true) {
        $s < 60 => $s . ' s temu',
        $s < 3600 => intdiv($s, 60) . ' min temu',
        $s < 86400 => intdiv($s, 3600) . ' godz. temu',
        default => fmt_when($dt),
    };
}

/** „40 s”, „3 min”, „2 godz.” – czas trwania. */
function fmt_duration(int $s): string
{
    return match (true) {
        $s < 60 => $s . ' s',
        $s < 3600 => (int) ceil($s / 60) . ' min',
        default => round($s / 3600, 1) . ' godz.',
    };
}

/** „Niedziela, 4 października 2026”. */
function fmt_long_date(int $t): string
{
    $days = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    $months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września',
        'października', 'listopada', 'grudnia'];
    return $days[(int) date('w', $t)] . ', ' . date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

function fmt_day_heading(string $dt): string
{
    $t = (int) ts($dt);
    $day = date('Y-m-d', $t);
    if ($day === date('Y-m-d')) {
        return 'Dzisiaj';
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Wczoraj';
    }
    $s = fmt_long_date($t);
    return date('Y', $t) === date('Y') ? substr($s, 0, strrpos($s, ' ')) : $s;
}

function fmt_bytes(int $b): string
{
    return match (true) {
        $b < 1024 => $b . ' B',
        $b < 1048576 => number_format($b / 1024, 1, ',', ' ') . ' kB',
        default => number_format($b / 1048576, 1, ',', ' ') . ' MB',
    };
}

// ---------- Log aplikacji ----------

/** Wpis w logu aplikacji; nieudane logowania w formacie dla fail2ban (rozdz. 5.3). */
function app_log(string $level, string $message): void
{
    $path = (string) cfg('log_path');
    $line = date('Y-m-d H:i:s') . " smsgui[$level]: " . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    if ($path === '' || @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log(rtrim($line));
    }
}
