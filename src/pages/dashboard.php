<?php
// Pulpit
if (is_post() && isset($_POST['sync'])) {
    $r = Sync::run(true);
    flash('ok', t('sync.done'), $r ? Sync::summary($r) : t('sync.busy'));
    redirect('./');
}
$today = date('Y-m-d 00:00:00');
$weekAgo = date('Y-m-d', strtotime('-6 days')); // „7 dni” = dziś i 6 poprzednich, od północy – tak samo jak filtr listy
$tiles = [
    'unread' => Db::row("SELECT COUNT(*) AS n, COUNT(DISTINCT phone) AS threads FROM messages WHERE direction = 'in' AND is_read = 0"),
    'queue' => Db::row("SELECT COUNT(*) AS n, MIN(COALESCE(scheduled_at, created_at)) AS oldest FROM messages WHERE direction = 'out' AND status IN ('queued','scheduled')"),
    'sent' => Db::row("SELECT COUNT(*) AS n, SUM(status = 'delivered') AS delivered FROM messages WHERE direction = 'out' AND status IN ('sent','delivered','undelivered') AND sent_at >= ?", [$today]),
    'errors' => Db::row("SELECT COUNT(*) AS n FROM messages WHERE direction = 'out' AND status IN ('failed','undelivered') AND updated_at >= ?", [$weekAgo . ' 00:00:00']),
    'contacts' => Db::row('SELECT COUNT(*) AS n, (SELECT COUNT(*) FROM `groups`) AS groups_n FROM contacts'),
    'calls' => Db::row('SELECT COUNT(*) AS n, MAX(received_at) AS last FROM calls WHERE received_at >= ?', [$today]),
];
$health = Health::run();
render('dashboard', ['weekAgo' => $weekAgo, 'title' => t('nav.dashboard'), 'nav' => 'dashboard', 'tiles' => $tiles, 'health' => $health,
    'recent' => Db::all('SELECT * FROM messages ORDER BY COALESCE(received_at, created_at) DESC, id DESC LIMIT 10'),
    'modem' => Status::modem(), 'service' => Status::service(), 'callsEnabled' => Calls::enabledInConf()]);
