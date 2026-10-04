<?php /** @var array $modems */ $device ??= GammuConf::load()?->get('gammu', 'device'); ?>
<section class="card" id="modem-state" hx-get="<?= e(url('modem', ['fragment' => 'state'])) ?>" hx-trigger="every 15s" hx-swap="outerHTML">
<header><h2>Stan modemu</h2></header>
<?php if ($modems === []): ?>
<?= Ui::empty('sim-card', 'Modem jeszcze się nie zgłosił', 'Gammu zapisuje stan modemu w tabeli phones po starcie usługi. Sprawdź usługę i log Gammu.', '<a href="' . e(url('config', ['tab' => 'service'])) . '" role="button" class="secondary">Usługa</a>') ?>
<?php endif ?>
<?php foreach ($modems as $m): $ok = Status::modemAvailable($m); ?>
<div class="row"><strong class="big-name"><?= e($m['modem']) ?></strong><?= $ok ? '<span class="badge badge-ok">Dostępny</span>' : '<span class="badge badge-err">Niedostępny od ' . e(fmt_when($m['gammu_updated_at'])) . '</span>' ?><?php if ($device): ?><span class="muted"><?= e($device) ?></span><?php endif ?></div>
<dl class="kv kv-170 gap-lg">
<dt>Sygnał</dt><dd><?php if ((int) $m['signal_pct'] >= 0): ?><progress class="signal" value="<?= (int) $m['signal_pct'] ?>" max="100" aria-label="Sygnał <?= (int) $m['signal_pct'] ?> %"></progress> <?= (int) $m['signal_pct'] ?> %<?php else: ?><span class="muted">brak danych</span><?php endif ?></dd>
<dt>Sieć</dt><dd><?= e($m['net_name'] ?: '—') ?><?= $m['net_code'] ? ' (' . e($m['net_code']) . ')' : '' ?></dd>
<dt>IMEI</dt><dd><span class="mono"><?= e($m['imei'] ?: '—') ?></span></dd>
<dt>IMSI</dt><dd><span class="mono"><?= e($m['imsi'] ?: '—') ?></span></dd>
<dt>Bateria</dt><dd><?= (int) $m['battery_pct'] >= 0 ? (int) $m['battery_pct'] . ' %' : '<span class="muted">modem nie podaje</span>' ?></dd>
<dt>Wysłane od startu</dt><dd><?= (int) $m['sent'] ?></dd>
<dt>Odebrane od startu</dt><dd><?= (int) $m['received'] ?></dd>
<dt>Klient</dt><dd><?= e($m['client'] ?: '—') ?></dd>
<dt>Ostatnia aktualizacja</dt><dd><?= e(fmt_ago($m['gammu_updated_at'])) ?> (<?= e(date('d.m.Y H:i:s', (int) ts($m['gammu_updated_at']))) ?>)</dd>
<?php if ($m['balance']): ?><dt>Ostatnie saldo</dt><dd><?= e($m['balance']) ?> · <?= e(fmt_when($m['balance_at'])) ?></dd><?php endif ?>
</dl>
<?php endforeach ?>
<p class="muted small gap-lg">Dane z tabeli <code>phones</code> · odświeżanie co 15 s</p>
</section>
