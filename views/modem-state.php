<?php /** @var array $modems */ $device ??= GammuConf::load()?->get('gammu', 'device'); ?>
<section class="card" id="modem-state" hx-get="<?= e(url('modem', ['fragment' => 'state'])) ?>" hx-trigger="every 15s" hx-swap="outerHTML">
<header><h2><?= e(t('modem.state')) ?></h2></header>
<?php if ($modems === []): ?>
<?= Ui::empty('sim-card', t('modem.not_reported'), t('modem.not_reported_text'), '<a href="' . e(url('config', ['tab' => 'service'])) . '" role="button" class="secondary">' . e(t('modem.service')) . '</a>') ?>
<?php endif ?>
<?php foreach ($modems as $m): $ok = Status::modemAvailable($m); ?>
<div class="row"><strong class="big-name"><?= e($m['modem']) ?></strong><?= $ok ? '<span class="badge badge-ok">' . e(t('modem.available')) . '</span>' : '<span class="badge badge-err">' . e(t('modem.unavailable_since', ['when' => fmt_when($m['gammu_updated_at'])])) . '</span>' ?><?php if ($device): ?><span class="muted"><?= e($device) ?></span><?php endif ?></div>
<dl class="kv kv-170 gap-lg">
<dt><?= e(t('dash.signal')) ?></dt><dd><?php if ((int) $m['signal_pct'] >= 0): ?><progress class="signal" value="<?= (int) $m['signal_pct'] ?>" max="100" aria-label="<?= e(t('dash.signal_label', ['pct' => (int) $m['signal_pct']])) ?>"></progress> <?= (int) $m['signal_pct'] ?> %<?php else: ?><span class="muted"><?= e(t('modem.no_data')) ?></span><?php endif ?></dd>
<dt><?= e(t('modem.network')) ?></dt><dd><?= e($m['net_name'] ?: '—') ?><?= $m['net_code'] ? ' (' . e($m['net_code']) . ')' : '' ?></dd>
<dt>IMEI</dt><dd><span class="mono"><?= e($m['imei'] ?: '—') ?></span></dd>
<dt>IMSI</dt><dd><span class="mono"><?= e($m['imsi'] ?: '—') ?></span></dd>
<dt><?= e(t('modem.battery')) ?></dt><dd><?= (int) $m['battery_pct'] >= 0 ? (int) $m['battery_pct'] . ' %' : '<span class="muted">' . e(t('modem.battery_na')) . '</span>' ?></dd>
<dt><?= e(t('modem.sent_since')) ?></dt><dd><?= (int) $m['sent'] ?></dd>
<dt><?= e(t('modem.received_since')) ?></dt><dd><?= (int) $m['received'] ?></dd>
<dt><?= e(t('modem.client')) ?></dt><dd><?= e($m['client'] ?: '—') ?></dd>
<dt><?= e(t('modem.updated')) ?></dt><dd><?= e(fmt_ago($m['gammu_updated_at'])) ?> (<?= e(fmt_date((int) ts($m['gammu_updated_at']), 'H:i:s')) ?>)</dd>
<?php if ($m['balance']): ?><dt><?= e(t('modem.balance')) ?></dt><dd><?= e($m['balance']) ?> · <?= e(fmt_when($m['balance_at'])) ?></dd><?php endif ?>
</dl>
<?php endforeach ?>
<p class="muted small gap-lg"><?= t('modem.source', ['table' => '<code>phones</code>']) ?></p>
</section>
