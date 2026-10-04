<?php /** @var array $tiles @var array $health @var array $recent @var ?array $modem @var string $service @var bool $callsEnabled */
$problems = array_values(array_filter($health, static fn ($h) => $h['level'] !== 'ok'));
$links = ['drd' => url('config'), 'conf' => url('config', ['tab' => 'editor']), 'service' => url('config', ['tab' => 'service']), 'modem' => url('modem'),
    'blocklist' => url('blocklist'), 'blocklist-conf' => url('blocklist'), 'queue' => url('sent', ['status' => 'pending']), 'include' => url('config', ['tab' => 'editor'])];
$worker = Settings::get('worker_seen_at');
?>
<header class="page-head"><div><h1>Pulpit</h1><p class="sub"><?= e(fmt_long_date(time())) ?></p></div>
<form class="actions" method="post" action="./"><?= csrf_field() ?><button type="submit" name="sync" value="1" class="secondary"><?= icon('refresh') ?>Synchronizuj teraz</button></form></header>
<?= flashes_html() ?>
<?php foreach (array_slice($problems, 0, 3) as $p): ?>
<div class="alert alert-<?= e($p['level']) ?>" role="alert"><?= icon($p['level'] === 'err' ? 'danger-circle' : 'danger-triangle') ?><div><strong><?= Health::html($p['text']) ?>.</strong> <?= e($p['hint'] ? ucfirst($p['hint']) . '.' : '') ?></div><?= isset($links[$p['id']]) ? '<a href="' . e($links[$p['id']]) . '">Przejdź</a>' : '' ?></div>
<?php endforeach ?>
<div class="tiles">
<a class="tile" href="<?= e(url('threads')) ?>"><span class="label">Nieprzeczytane</span><span class="value"><?= (int) $tiles['unread']['n'] ?></span><span class="hint"><?= (int) $tiles['unread']['n'] ? 'w ' . (int) $tiles['unread']['threads'] . ' ' . plural((int) $tiles['unread']['threads'], 'rozmowie', 'rozmowach', 'rozmowach') : 'wszystko przeczytane' ?></span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'pending'])) ?>"><span class="label">W kolejce</span><span class="value"><?= (int) $tiles['queue']['n'] ?></span><span class="hint"><?= $tiles['queue']['oldest'] ? 'najstarsza ' . e(fmt_duration(max(0, time() - (int) ts($tiles['queue']['oldest'])))) : 'kolejka pusta' ?></span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'done', 'sent_from' => date('Y-m-d')])) ?>"><span class="label">Wysłane dziś</span><span class="value"><?= (int) $tiles['sent']['n'] ?></span><span class="hint"><?= (int) $tiles['sent']['delivered'] ?> doręczone</span></a>
<a class="tile" href="<?= e(url('sent', ['status' => 'problem', 'changed_from' => $weekAgo])) ?>"><span class="label">Błędy (7 dni)</span><span class="value<?= (int) $tiles['errors']['n'] ? ' err' : '' ?>"><?= (int) $tiles['errors']['n'] ?></span><span class="hint">błąd lub niedoręczona</span></a>
<a class="tile" href="<?= e(url('contacts')) ?>"><span class="label">Kontakty</span><span class="value"><?= (int) $tiles['contacts']['n'] ?></span><span class="hint">w <?= (int) $tiles['contacts']['groups_n'] ?> <?= plural((int) $tiles['contacts']['groups_n'], 'grupie', 'grupach', 'grupach') ?></span></a>
<?php if ($callsEnabled || (int) $tiles['calls']['n'] > 0): ?><a class="tile" href="<?= e(url('calls')) ?>"><span class="label">Połączenia dziś</span><span class="value"><?= (int) $tiles['calls']['n'] ?></span><span class="hint"><?= $tiles['calls']['last'] ? 'odrzucone · ostatnie ' . e(date('H:i', (int) ts($tiles['calls']['last']))) : 'odrzucane i zapisywane' ?></span></a><?php endif ?>
</div>
<div class="split">
<section class="card flush">
<header><h2>Ostatnie wiadomości</h2><a href="<?= e(url('threads')) ?>"><strong>Wszystkie rozmowy</strong></a></header>
<?php if ($recent === []): ?><?= Ui::empty('chat-round-line', 'Brak wiadomości', 'Wyślij pierwszy SMS albo poczekaj na odebrany.', '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . 'Nowa wiadomość</a>') ?><?php else: ?>
<div class="table-wrap"><table class="dash-recent">
<thead><tr><th scope="col"><span class="visually-hidden">Kierunek</span></th><th scope="col">Kto</th><th scope="col">Treść</th><th scope="col">Status</th><th scope="col">Kiedy</th></tr></thead>
<tbody>
<?php foreach ($recent as $m): $in = $m['direction'] === 'in'; ?>
<tr><td><span title="<?= $in ? 'Odebrana' : 'Wysłana' ?>"><?= icon($in ? 'inbox-in' : 'plain') ?><span class="visually-hidden"><?= $in ? 'Odebrana' : 'Wysłana' ?></span></span></td>
<td><a href="<?= e(url('threads', ['phone' => $m['phone']])) ?>"><strong><?= e(Contacts::display($m['phone'])) ?></strong></a></td>
<td><span class="truncate muted"><?= e(Ui::snippet($m['body'], 70)) ?></span></td><td><?= Ui::status($m, false) ?></td>
<td><span class="muted"><?= e(fmt_short($m['received_at'] ?? $m['created_at'])) ?></span></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
<div class="stack">
<section class="card"><header><h2>Usługa i modem</h2></header>
<dl class="kv">
<dt>Gammu SMSD</dt><dd><span class="dot dot-<?= $service === 'active' ? 'ok' : 'err' ?>"></span> <?= e($service) ?></dd>
<dt>Proces w tle</dt><dd><span class="dot dot-<?= $worker && time() - (int) ts($worker) <= 60 ? 'ok' : 'err' ?>"></span> <?= $worker ? 'ostatni przebieg ' . e(fmt_ago($worker)) : 'nie działa' ?></dd>
<dt>Synchronizacja</dt><dd><?= e(fmt_ago(Settings::get('last_sync_at'))) ?></dd>
<?php if ($modem): ?>
<dt>Modem</dt><dd><?= e($modem['modem']) ?><?= Status::modemAvailable($modem) ? '' : ' · <span class="badge badge-err">niedostępny</span>' ?></dd>
<dt>Sygnał</dt><dd><?php if ((int) $modem['signal_pct'] >= 0): ?><progress class="signal" value="<?= (int) $modem['signal_pct'] ?>" max="100" aria-label="Sygnał <?= (int) $modem['signal_pct'] ?> %"></progress> <?= (int) $modem['signal_pct'] ?> %<?php else: ?>—<?php endif ?></dd>
<dt>Operator</dt><dd><?= e($modem['net_name'] ?: '—') ?><?= $modem['net_code'] ? ' (' . e($modem['net_code']) . ')' : '' ?></dd>
<dt>Stan z phones</dt><dd><?= e(fmt_ago($modem['gammu_updated_at'])) ?></dd>
<?php else: ?><dt>Modem</dt><dd><span class="muted">jeszcze się nie zgłosił</span></dd><?php endif ?>
</dl>
<p class="gap-lg"><a href="<?= e(url('modem')) ?>"><strong>Szczegóły modemu i USSD</strong></a></p>
</section>
<section class="card"><header><h2>Kontrola zdrowia</h2></header>
<ul class="health">
<?php foreach ($health as $h): ?><li class="<?= e($h['level']) ?>"><?= icon(['ok' => 'check-circle', 'warn' => 'danger-triangle', 'err' => 'danger-circle'][$h['level']]) ?><span><?= Health::html($h['text']) ?></span></li><?php endforeach ?>
</ul></section>
</div>
</div>
