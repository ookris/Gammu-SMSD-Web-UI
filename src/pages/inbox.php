<?php
// Odebrane
if (is_post()) {
    if (input('block') !== '') {
        Blocklist::blockFromPanel(input('block'), t('inbox.block_note'));
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
        flash('ok', input('action') === 'delete' ? tn('inbox.deleted', $n) : t('common.saved'));
    }
    back(url('inbox'));
}

$f = ['q' => input('q'), 'from' => input('from'), 'to' => input('to'), 'unread' => input('unread')];
$f = clean_dates($f, ['from', 'to']);
$where = ["direction = 'in'"];
$params = [];
if ($f['q'] !== '') {
    $digits = preg_replace('/[^0-9]/', '', $f['q']);
    $where[] = '(body LIKE ? OR phone LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
    array_push($params, '%' . $f['q'] . '%', '%' . ($digits !== '' ? ltrim($digits, '0') : $f['q']) . '%', '%' . $f['q'] . '%');
}
if ($f['from'] !== '') {
    $where[] = 'received_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
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

render('inbox', ['title' => t('nav.inbox'), 'nav' => 'inbox', 'rows' => $rows, 'total' => $total, 'page' => $page, 'f' => $f, 'stats' => $stats,
    'blocked' => Blocklist::blockedAmong(array_column($rows, 'phone'))]);
