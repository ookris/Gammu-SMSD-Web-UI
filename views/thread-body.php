<?php /** @var string $phone @var array $items @var string $version */ $day = null; ?>
<div class="thread-body" id="thread-body" aria-live="polite" data-scroll-bottom
     hx-get="<?= e(url('threads', ['phone' => $phone, 'fragment' => 'body', 'v' => $version])) ?>" hx-trigger="every 10s" hx-swap="outerHTML">
<?php foreach ($items as $it): $d = substr((string) $it['at'], 0, 10); ?>
<?php if ($d !== $day): $day = $d; ?><div class="day"><?= e(fmt_day_heading((string) $it['at'])) ?></div><?php endif ?>
<?php if ($it['kind'] === 'call'): ?>
<div class="event"><span><?= icon('end-call') ?><?= e(t('threads.call_rejected', ['time' => date('H:i', (int) ts($it['at']))])) ?></span></div>
<?php else: $out = $it['direction'] === 'out'; ?>
<div class="msg<?= $out ? ' out' : '' ?>">
<div class="bubble"><?= nl2br(e($it['body'])) ?></div>
<div class="meta"><?= e(date('H:i', (int) ts($it['at']))) ?><?= $it['modem'] ? ' · ' . e($it['modem']) : '' ?><?= $it['batch_id'] ? ' · ' . e(t('threads.batch')) : '' ?><?= $it['source'] === 'external' ? ' · ' . e(t('sent.external')) : '' ?><?= $it['incomplete'] ? ' · ' . e(t('inbox.incomplete')) : '' ?><?= $it['flash'] ? ' · Flash' : '' ?>
<?php if ($out): ?><?= Ui::status($it, false) ?><?php endif ?></div>
<?php if ($out && in_array($it['status'], ['failed', 'undelivered'], true) && $it['error']): ?><div class="meta err"><?= e(tr($it['error'])) ?></div><?php endif ?>
</div>
<?php endif ?>
<?php endforeach ?>
<?php if ($items === []): ?><p class="muted small"><?= e(t('threads.no_messages')) ?></p><?php endif ?>
</div>
