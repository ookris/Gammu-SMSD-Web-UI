<?php /** @var array $tiles @var array $health @var array $recent @var ?array $modem @var string $service @var bool $callsEnabled */
$problems = array_values(array_filter($health, static fn ($h) => $h['level'] !== 'ok'));
$links = ['drd' => url('config'), 'conf' => url('config', ['tab' => 'editor']), 'service' => url('config', ['tab' => 'service']), 'modem' => url('modem'),
    'blocklist' => url('blocklist'), 'blocklist-conf' => url('blocklist'), 'queue' => url('sent', ['status' => 'pending']), 'include' => url('config', ['tab' => 'editor'])];
$worker = Settings::get('worker_seen_at');
?>
<header class="page-head"><div><h1><?= e(t('nav.dashboard')) ?></h1><p class="sub"><?= e(fmt_long_date(time())) ?></p></div>
<form class="actions" method="post" action="./"><?= csrf_field() ?><button type="submit" name="sync" value="1" class="secondary"><?= icon('refresh') ?><?= e(t('dash.sync_now')) ?></button></form></header>
<?= flashes_html() ?>
<?php foreach (array_slice($problems, 0, 3) as $p): ?>
<div class="alert alert-<?= e($p['level']) ?>" role="alert"><?= icon($p['level'] === 'err' ? 'danger-circle' : 'danger-triangle') ?><div><strong><?= Health::html($p['text']) ?>.</strong> <?= e($p['hint'] ? ucfirst($p['hint']) . '.' : '') ?></div><?= isset($links[$p['id']]) ? '<a href="' . e($links[$p['id']]) . '">' . e(t('dash.go')) . '</a>' : '' ?></div>
<?php endforeach ?>
<div class="tiles">
<a class="tile" href="<?= e(url('threads')) ?>"><span class="label"><?= e(t('dash.unread')) ?></span><span class="value"><?= (int) $tiles['unread']['n'] ?></span><span class="hint"><?= e((int) $tiles['unread']['n'] ? tn('dash.unread_in', (int) $tiles['unread']['threads']) : t('dash.all_read')) ?></span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'pending'])) ?>"><span class="label"><?= e(t('dash.queue')) ?></span><span class="value"><?= (int) $tiles['queue']['n'] ?></span><span class="hint"><?= e($tiles['queue']['oldest'] ? t('dash.oldest', ['time' => fmt_duration(max(0, time() - (int) ts($tiles['queue']['oldest'])))]) : t('dash.queue_empty')) ?></span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'done', 'sent_from' => date('Y-m-d')])) ?>"><span class="label"><?= e(t('dash.sent_today')) ?></span><span class="value"><?= (int) $tiles['sent']['n'] ?></span><span class="hint"><?= e(t('sent.n_delivered', ['n' => (int) $tiles['sent']['delivered']])) ?></span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'problem', 'changed_from' => $weekAgo])) ?>"><span class="label"><?= e(t('dash.errors')) ?></span><span class="value<?= (int) $tiles['errors']['n'] ? ' err' : '' ?>"><?= (int) $tiles['errors']['n'] ?></span><span class="hint"><?= e(t('dash.errors_hint')) ?></span></a>
<a class="tile" href="<?= e(url('contacts')) ?>"><span class="label"><?= e(t('nav.contacts')) ?></span><span class="value"><?= (int) $tiles['contacts']['n'] ?></span><span class="hint"><?= e(tn('dash.in_groups', (int) $tiles['contacts']['groups_n'])) ?></span></a>
<?php if ($callsEnabled || (int) $tiles['calls']['n'] > 0): ?><a class="tile" href="<?= e(url('calls')) ?>"><span class="label"><?= e(t('dash.calls_today')) ?></span><span class="value"><?= (int) $tiles['calls']['n'] ?></span><span class="hint"><?= e($tiles['calls']['last'] ? t('dash.calls_last', ['time' => date('H:i', (int) ts($tiles['calls']['last']))]) : t('dash.calls_hint')) ?></span></a><?php endif ?>
</div>
<div class="split">
<section class="card flush">
<header><h2><?= e(t('dash.recent')) ?></h2><a href="<?= e(url('threads')) ?>"><strong><?= e(t('dash.all_threads')) ?></strong></a></header>
<?php if ($recent === []): ?><?= Ui::empty('chat-round-line', t('dash.recent_empty'), t('dash.recent_empty_text'), '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . e(t('nav.compose')) . '</a>') ?><?php else: ?>
<div class="table-wrap"><table class="dash-recent">
<thead><tr><th scope="col"><span class="visually-hidden"><?= e(t('dash.direction')) ?></span></th><th scope="col"><?= e(t('dash.who')) ?></th><th scope="col"><?= e(t('common.body')) ?></th><th scope="col"><?= e(t('common.status')) ?></th><th scope="col"><?= e(t('dash.when')) ?></th></tr></thead>
<tbody>
<?php foreach ($recent as $m): $in = $m['direction'] === 'in'; ?>
<tr><td><span title="<?= e(t($in ? 'status.received' : 'status.sent')) ?>"><?= icon($in ? 'inbox-in' : 'plain') ?><span class="visually-hidden"><?= e(t($in ? 'status.received' : 'status.sent')) ?></span></span></td>
<td><a href="<?= e(url('threads', ['phone' => $m['phone']])) ?>"><strong><?= e(Contacts::display($m['phone'])) ?></strong></a></td>
<td><span class="truncate muted"><?= e(Ui::snippet($m['body'], 70)) ?></span></td><td><?= Ui::status($m, false) ?></td>
<td><span class="muted"><?= e(fmt_short($m['received_at'] ?? $m['created_at'])) ?></span></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
<div class="stack">
<section class="card"><header><h2><?= e(t('dash.service_modem')) ?></h2></header>
<dl class="kv">
<dt>Gammu SMSD</dt><dd><span class="dot dot-<?= $service === 'active' ? 'ok' : 'err' ?>"></span> <?= e($service) ?></dd>
<dt><?= e(t('dash.worker')) ?></dt><dd><span class="dot dot-<?= $worker && time() - (int) ts($worker) <= 60 ? 'ok' : 'err' ?>"></span> <?= e($worker ? t('dash.worker_last', ['ago' => fmt_ago($worker)]) : t('dash.worker_down')) ?></dd>
<dt><?= e(t('dash.sync')) ?></dt><dd><?= e(fmt_ago(Settings::get('last_sync_at'))) ?></dd>
<?php if ($modem): ?>
<dt><?= e(t('inbox.modem')) ?></dt><dd><?= e($modem['modem']) ?><?= Status::modemAvailable($modem) ? '' : ' · <span class="badge badge-err">' . e(t('dash.unavailable')) . '</span>' ?></dd>
<dt><?= e(t('dash.signal')) ?></dt><dd><?php if ((int) $modem['signal_pct'] >= 0): ?><progress class="signal" value="<?= (int) $modem['signal_pct'] ?>" max="100" aria-label="<?= e(t('dash.signal_label', ['pct' => (int) $modem['signal_pct']])) ?>"></progress> <?= (int) $modem['signal_pct'] ?> %<?php else: ?>—<?php endif ?></dd>
<dt><?= e(t('dash.operator')) ?></dt><dd><?= e($modem['net_name'] ?: '—') ?><?= $modem['net_code'] ? ' (' . e($modem['net_code']) . ')' : '' ?></dd>
<dt><?= e(t('dash.phones_state')) ?></dt><dd><?= e(fmt_ago($modem['gammu_updated_at'])) ?></dd>
<?php else: ?><dt><?= e(t('inbox.modem')) ?></dt><dd><span class="muted"><?= e(t('dash.not_reported')) ?></span></dd><?php endif ?>
</dl>
<p class="gap-lg"><a href="<?= e(url('modem')) ?>"><strong><?= e(t('dash.modem_details')) ?></strong></a></p>
</section>
<section class="card"><header><h2><?= e(t('dash.health')) ?></h2></header>
<ul class="health">
<?php foreach ($health as $h): ?><li class="<?= e($h['level']) ?>"><?= icon(['ok' => 'check-circle', 'warn' => 'danger-triangle', 'err' => 'danger-circle'][$h['level']]) ?><span><?= Health::html($h['text']) ?></span></li><?php endforeach ?>
</ul></section>
</div>
</div>
