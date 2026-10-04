<?php
// Raport wysyłki do wielu odbiorców (rozdz. 2.3)
$id = input('id');
if (!Batch::valid($id) || Db::val('SELECT 1 FROM messages WHERE batch_id = ? LIMIT 1', [$id]) === null) {
    http_response_code(404);
    echo view('error', ['code' => 404]);
    exit;
}
if (is_post()) {
    if (isset($_POST['retry_failed'])) {
        $n = Batch::retryFailed($id);
        flash('ok', tn('batch.retried', $n));
    } elseif (isset($_POST['cancel_rest'])) {
        [$ok, $busy] = Batch::cancelRemaining($id);
        flash($busy ? 'warn' : 'ok', tn('batch.cancelled', $ok), $busy ? tn('batch.busy', $busy) : '');
    }
    redirect(url('batch', ['id' => $id]));
}
$data = ['id' => $id, 'meta' => Batch::meta($id), 'stats' => Batch::stats($id)];
if (input('fragment') === 'progress') {
    echo view('batch-progress', $data);
    exit;
}
render('batch', $data + ['title' => t('batch.title'), 'nav' => 'sent', 'rows' => Batch::recipients($id)]);
