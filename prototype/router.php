<?php
// Serwer prototypu: php -S 127.0.0.1:8081 -t prototype prototype/router.php
// Dodaje te same nagłówki bezpieczeństwa co panel (rozdz. 5.4), żeby błędy CSP wyszły już na prototypie.

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') {
    $path = '/index.html';
}
$file = realpath(__DIR__ . $path);
if ($file === false || !str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    return true;
}
$types = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'md' => 'text/plain; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
];
$type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
if ($type === null) {
    http_response_code(403);
    return true;
}
// Plik wysyłamy sami – wtedy nagłówki powyżej na pewno trafiają do przeglądarki
header('Content-Type: ' . $type);
readfile($file);
return true;
