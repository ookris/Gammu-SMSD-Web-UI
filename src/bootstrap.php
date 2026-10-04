<?php
// Wspólny start panelu, procesu w tle i CLI: autoloader, konfiguracja, strefa czasowa, obsługa błędów.
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_VERSION = '1.0-dev';

spl_autoload_register(static function (string $class): void {
    if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $class)) {
        $file = __DIR__ . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/helpers.php';

/** Konfiguracja: config.example.php nałożony przez config/config.php (rekurencyjnie dla tablic). */
function app_config(?array $override = null): array
{
    static $config = null;
    if ($override !== null) {
        $config = $override;
    }
    if ($config === null) {
        $config = require APP_ROOT . '/config/config.example.php';
        $local = getenv('SMSGUI_CONFIG') ?: APP_ROOT . '/config/config.php';
        if (is_file($local)) {
            $config = array_replace_recursive($config, require $local);
        }
    }
    return $config;
}

date_default_timezone_set((string) cfg('timezone', 'UTC'));
mb_internal_encoding('UTF-8');

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    app_log('error', $e::class . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Błąd: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $dbError = $e instanceof PDOException;
    echo view($dbError ? 'error-db' : 'error', ['error' => cfg('debug') ? $e : null]);
});
