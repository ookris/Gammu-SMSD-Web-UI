<?php
// Log Gammu (rozdz. 2.11) – plik czytany od końca
$f = ['lines' => in_array(input('lines'), ['100', '500', '2000'], true) ? input('lines') : '500',
    'order' => input('order') === 'asc' ? 'asc' : 'desc', 'q' => input('q'),
    'hl' => input('hl', isset($_GET['lines']) ? '' : '1'), 'auto' => input('auto')];
$path = Service::logPath();
$lines = $path !== null && is_readable($path) ? Service::tail($path, (int) $f['lines']) : [];
if ($f['q'] !== '') {
    $lines = array_values(array_filter($lines, static fn ($l) => mb_stripos($l, $f['q']) !== false));
}
if ($f['order'] === 'desc') {
    $lines = array_reverse($lines);
}
$data = ['f' => $f, 'path' => $path, 'lines' => $lines, 'size' => $path && is_file($path) ? filesize($path) : null,
    'mtime' => $path && is_file($path) ? filemtime($path) : null, 'readable' => $path !== null && is_readable($path)];
if (input('fragment') === 'lines') {
    echo view('log-lines', $data);
    exit;
}
render('log', $data + ['title' => t('nav.log'), 'nav' => 'log']);
