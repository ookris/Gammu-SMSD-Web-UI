<?php
// Front controller panelu: nagłówki bezpieczeństwa, routing ?p=, logowanie, centralne sprawdzanie CSRF.
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
        return false; // wbudowany serwer PHP sam wyśle plik statyczny
    }
}

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

require __DIR__ . '/../src/bootstrap.php';

if (Auth::https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

Auth::startSession();

$page = $_GET['p'] ?? 'dashboard';
if (!is_string($page) || !preg_match('/^[a-z][a-z0-9-]{0,40}$/', $page)) {
    $page = '404';
}
$public = ['login'];

if (!in_array($page, $public, true) && Auth::user() === null) {
    if (is_htmx()) {
        header('HX-Redirect: ' . url('login'));
        http_response_code(401);
        exit;
    }
    redirect(url('login', ['next' => $page === 'dashboard' ? null : current_url()]));
}

if (is_post() && !csrf_valid()) {
    http_response_code(403);
    if (is_htmx()) {
        echo '<div class="alert alert-err tight" role="alert">' . icon('danger-circle') . '<div>' . e(t('auth.csrf')) . '</div></div>';
        exit;
    }
    echo view('error', ['code' => 403, 'title' => 'Odmowa dostępu', 'message' => t('auth.csrf')]);
    exit;
}

// Synchronizacja przy odświeżeniu strony – zabezpieczenie, gdy proces w tle nie działa (D14)
if (Auth::user() !== null && !is_post()) {
    Sync::onPageLoad();
}

$file = APP_ROOT . '/src/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    echo view('error', ['code' => 404]);
    exit;
}
(static function (string $file): void {
    require $file;
})($file);
