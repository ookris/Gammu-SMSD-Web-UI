<?php
// Wysłane / kolejka
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
    match ($action) {
        'cancel' => flash($busy > 0 ? 'warn' : 'ok', tn('sent.cancelled', $ok), $busy > 0 ? tn('sent.busy', $busy) : ''),
        'retry' => $ok > 0 ? flash('ok', tn('sent.retried', $ok), t('sent.retried_text')) : flash('warn', t('sent.nothing_retried'), t('sent.nothing_retried_text')),
        'delete' => flash('ok', tn('sent.deleted', $ok), $ok < count($ids) ? t('sent.cancel_first') : ''),
        default => null,
    };
    back(url('sent'));
}

$f = ['q' => input('q'), 'status' => input('status'), 'source' => input('source'), 'from' => input('from'), 'to' => input('to'), 'batch' => input('batch'),
    'sent_from' => input('sent_from'), 'changed_from' => input('changed_from')];
$f = clean_dates($f, ['from', 'to', 'sent_from', 'changed_from']);
$where = ["direction = 'out'"];
$params = [];
if ($f['q'] !== '') {
    $digits = preg_replace('/[^0-9]/', '', $f['q']);
    $where[] = '(body LIKE ? OR phone LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
    array_push($params, '%' . $f['q'] . '%', '%' . ($digits !== '' ? ltrim($digits, '0') : $f['q']) . '%', '%' . $f['q'] . '%');
}
// Statusy pojedyncze i zbiorcze (te same zbiory co kafelki pulpitu): pending, done, problem
$groups = ['pending' => "status IN ('scheduled','queued')", 'done' => "status IN ('sent','delivered','undelivered')",
    'problem' => "status IN ('failed','undelivered')", 'retrying' => "status = 'queued' AND retries > 0", 'queued' => "status = 'queued' AND retries = 0"];
if (isset($groups[$f['status']])) {
    $where[] = $groups[$f['status']];
} elseif (in_array($f['status'], ['scheduled', 'sent', 'delivered', 'undelivered', 'failed', 'cancelled'], true)) {
    $where[] = 'status = ?';
    $params[] = $f['status'];
}
if (in_array($f['source'], ['gui', 'external', 'api'], true)) {
    $where[] = 'source = ?';
    $params[] = $f['source'];
}
if ($f['from'] !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}
foreach (['sent_from' => 'sent_at', 'changed_from' => 'updated_at'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col >= ?";
        $params[] = $f[$k] . ' 00:00:00';
    }
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

render('sent', ['title' => t('nav.sent'), 'nav' => 'sent', 'rows' => $rows, 'total' => $total, 'page' => $page, 'f' => $f,
    'last' => $last, 'lastMeta' => $last ? Batch::meta($last) : null, 'lastStats' => $last ? Batch::stats($last) : null]);
