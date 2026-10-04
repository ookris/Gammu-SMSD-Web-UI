<?php
// Połączenia przychodzące (rozdz. 2.13)
if (is_post()) {
    if (input('block') !== '') {
        Blocklist::blockFromPanel(input('block'), 'z połączeń');
        back(url('calls'));
    }
    $ids = ($one = (int) input('remove')) > 0 ? [$one] : input_ids();
    if ($ids !== []) {
        $n = Db::exec('DELETE FROM calls WHERE id IN (' . Db::in($ids) . ')', $ids);
        flash('ok', 'Usunięto ' . $n . ' ' . plural($n, 'połączenie', 'połączenia', 'połączeń') . '.');
    }
    back(url('calls'));
}
$f = ['q' => input('q'), 'from' => input('from'), 'to' => input('to')];
$where = ['1=1'];
$params = [];
if ($f['q'] !== '') {
    $digits = preg_replace('/[^0-9]/', '', $f['q']);
    $where[] = '(phone LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
    array_push($params, '%' . ($digits !== '' ? ltrim($digits, '0') : $f['q']) . '%', '%' . $f['q'] . '%');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
    $where[] = 'received_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
    $where[] = 'received_at <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}
$w = implode(' AND ', $where);
$page = Ui::page();
$total = (int) Db::val("SELECT COUNT(*) FROM calls WHERE $w", $params);
$rows = Db::all("SELECT * FROM calls WHERE $w ORDER BY received_at DESC, id DESC LIMIT " . Ui::PER_PAGE . ' OFFSET ' . ($page - 1) * Ui::PER_PAGE, $params);
Db::exec('UPDATE calls SET is_read = 1 WHERE is_read = 0');
Status::forget();
render('calls', ['title' => 'Połączenia', 'nav' => 'calls', 'rows' => $rows, 'total' => $total, 'page' => $page, 'f' => $f,
    'month' => (int) Db::val('SELECT COUNT(*) FROM calls WHERE received_at >= ?', [date('Y-m-01 00:00:00')]), 'enabled' => Calls::enabledInConf(),
    'blocked' => Blocklist::blockedAmong(array_values(array_filter(array_column($rows, 'phone'))))]);
