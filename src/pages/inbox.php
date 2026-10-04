<?php
// Odebrane (rozdz. 2.5)
if (is_post()) {
    if (input('block') !== '') {
        Blocklist::blockFromPanel(input('block'), 'z odebranych');
        back(url('inbox'));
    }
    $ids = input_ids();
    $n = count($ids);
    match (input('action')) {
        'read' => Db::exec("UPDATE messages SET is_read = 1, updated_at = NOW() WHERE direction = 'in' AND id IN (" . Db::in($ids) . ')', $ids),
        'unread' => Db::exec("UPDATE messages SET is_read = 0, updated_at = NOW() WHERE direction = 'in' AND id IN (" . Db::in($ids) . ')', $ids),
        'delete' => Messages::delete($ids, 'in'),
        default => 0,
    };
    if ($n > 0) {
        flash('ok', match (input('action')) { 'delete' => 'Usunięto ' . $n . ' ' . plural($n, 'wiadomość', 'wiadomości', 'wiadomości') . '.', default => 'Zapisano.' });
    }
    back(url('inbox'));
}

$f = ['q' => input('q'), 'from' => input('from'), 'to' => input('to'), 'unread' => input('unread')];
$where = ["direction = 'in'"];
$params = [];
if ($f['q'] !== '') {
    $digits = preg_replace('/[^0-9]/', '', $f['q']);
    $where[] = '(body LIKE ? OR phone LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
    array_push($params, '%' . $f['q'] . '%', '%' . ($digits !== '' ? ltrim($digits, '0') : $f['q']) . '%', '%' . $f['q'] . '%');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
    $where[] = 'received_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
    $where[] = 'received_at <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}
if ($f['unread'] !== '') {
    $where[] = 'is_read = 0';
}
$w = implode(' AND ', $where);
$page = Ui::page();
$total = (int) Db::val("SELECT COUNT(*) FROM messages WHERE $w", $params);
$rows = Db::all("SELECT * FROM messages WHERE $w ORDER BY id DESC LIMIT " . Ui::PER_PAGE . ' OFFSET ' . ($page - 1) * Ui::PER_PAGE, $params);
$stats = Db::row("SELECT COUNT(*) AS n, SUM(is_read = 0) AS unread FROM messages WHERE direction = 'in'");

render('inbox', ['title' => 'Odebrane', 'nav' => 'inbox', 'rows' => $rows, 'total' => $total, 'page' => $page, 'f' => $f, 'stats' => $stats,
    'blocked' => Blocklist::blockedAmong(array_column($rows, 'phone'))]);
