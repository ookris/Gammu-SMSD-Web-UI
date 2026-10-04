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
        flash('ok', 'Ponowiono ' . $n . ' ' . plural($n, 'wiadomość', 'wiadomości', 'wiadomości') . '.');
    } elseif (isset($_POST['cancel_rest'])) {
        [$ok, $busy] = Batch::cancelRemaining($id);
        flash($busy ? 'warn' : 'ok', 'Anulowano ' . $ok . ' ' . plural($ok, 'wiadomość', 'wiadomości', 'wiadomości') . '.',
            $busy ? $busy . ' jest już wysyłana przez Gammu.' : '');
    }
    redirect(url('batch', ['id' => $id]));
}
$data = ['id' => $id, 'meta' => Batch::meta($id), 'stats' => Batch::stats($id)];
if (input('fragment') === 'progress') {
    echo view('batch-progress', $data);
    exit;
}
render('batch', $data + ['title' => 'Raport wysyłki', 'nav' => 'sent', 'rows' => Batch::recipients($id)]);
