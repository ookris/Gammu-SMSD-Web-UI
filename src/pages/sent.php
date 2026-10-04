<?php
// Wysłane / kolejka (rozdz. 2.6)
if (is_post()) {
    $ids = input_ids();
    if (($one = (int) input('cancel')) > 0 || ($one = (int) input('retry')) > 0 || ($one = (int) input('remove')) > 0) {
        $action = isset($_POST['cancel']) ? 'cancel' : (isset($_POST['retry']) ? 'retry' : 'delete');
        $ids = [$one];
    } else {
        $action = input('action');
    }
    $ok = $busy = 0;
    foreach ($ids as $id) {
        if ($action === 'cancel') {
            $r = Outbox::cancel($id);
            $ok += (int) ($r === 'cancelled');
            $busy += (int) ($r === 'busy');
        } elseif ($action === 'retry') {
            $ok += (int) Outbox::retry($id);
        }
    }
    if ($action === 'delete') {
        $ok = Messages::delete($ids, 'out');
    }
    $word = static fn (int $n) => $n . ' ' . plural($n, 'wiadomość', 'wiadomości', 'wiadomości');
    match ($action) {
        'cancel' => $busy > 0 ? flash('warn', 'Anulowano: ' . $word($ok) . '.', $word($busy) . ' jest już wysyłana przez Gammu – nie da się jej zatrzymać.')
            : flash('ok', 'Anulowano: ' . $word($ok) . '.'),
        'retry' => flash($ok > 0 ? 'ok' : 'warn', $ok > 0 ? 'Ponowiono: ' . $word($ok) . '.' : 'Nic nie ponowiono.', $ok > 0 ? 'Wiadomości wróciły do kolejki Gammu.' : 'Ponowić można wiadomości z błędem lub niedoręczone.'),
        'delete' => flash('ok', 'Usunięto z historii: ' . $word($ok) . '.', $ok < count($ids) ? 'Wiadomości w kolejce najpierw anuluj.' : ''),
        default => null,
    };
    back(url('sent'));
}

$f = ['q' => input('q'), 'status' => input('status'), 'source' => input('source'), 'from' => input('from'), 'to' => input('to'), 'batch' => input('batch')];
$where = ["direction = 'out'"];
$params = [];
if ($f['q'] !== '') {
    $digits = preg_replace('/[^0-9]/', '', $f['q']);
    $where[] = '(body LIKE ? OR phone LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
    array_push($params, '%' . $f['q'] . '%', '%' . ($digits !== '' ? ltrim($digits, '0') : $f['q']) . '%', '%' . $f['q'] . '%');
}
$statuses = ['scheduled', 'queued', 'retrying', 'sent', 'delivered', 'undelivered', 'failed', 'cancelled'];
if (in_array($f['status'], $statuses, true)) {
    $where[] = match ($f['status']) { 'retrying' => "status = 'queued' AND retries > 0", 'queued' => "status = 'queued' AND retries = 0", default => 'status = ?' };
    if (!in_array($f['status'], ['retrying', 'queued'], true)) {
        $params[] = $f['status'];
    }
}
if (in_array($f['source'], ['gui', 'external', 'api'], true)) {
    $where[] = 'source = ?';
    $params[] = $f['source'];
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
    $where[] = 'created_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
    $where[] = 'created_at <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}
if (Batch::valid($f['batch'])) {
    $where[] = 'batch_id = ?';
    $params[] = $f['batch'];
}
$w = implode(' AND ', $where);
$page = Ui::page();
$total = (int) Db::val("SELECT COUNT(*) FROM messages WHERE $w", $params);
$rows = Db::all("SELECT * FROM messages WHERE $w ORDER BY id DESC LIMIT " . Ui::PER_PAGE . ' OFFSET ' . ($page - 1) * Ui::PER_PAGE, $params);
$last = Batch::last();

render('sent', ['title' => 'Wysłane', 'nav' => 'sent', 'rows' => $rows, 'total' => $total, 'page' => $page, 'f' => $f,
    'last' => $last, 'lastMeta' => $last ? Batch::meta($last) : null, 'lastStats' => $last ? Batch::stats($last) : null]);
