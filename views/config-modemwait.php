<?php /** @var ?array $op */
$back = $op ? Service::modemBack($op['started']) : null;
$elapsed = $op ? time() - (int) ts($op['started']) : 99;
$waiting = $op !== null && $back === null && $elapsed <= 30;
?>
<div id="modem-wait"<?= $waiting ? ' hx-get="' . e(url('config', ['fragment' => 'modemwait'])) . '" hx-trigger="every 2s" hx-swap="outerHTML"' : '' ?>>
<?php if ($back !== null): ?>
<p class="status-line"><?= icon('check-circle') ?><?= t('config.modem_back', ['modem' => e($back['ID']), 'table' => '<code>phones</code>', 'n' => max(0, (int) ts($back['UpdatedInDB']) - (int) ts($op['started']))]) ?></p>
<?php elseif ($waiting): ?>
<div class="ussd-wait" role="status"><?= icon('hourglass') ?><div><strong><?= e(t('config.modem_waiting')) ?></strong><div class="muted small"><?= e(t('config.modem_waiting_text', ['n' => $elapsed])) ?></div></div></div>
<?php elseif ($op !== null): ?>
<p class="status-line warn"><?= icon('danger-triangle') ?><?= e(t('config.modem_timeout')) ?></p>
<?php endif ?>
</div>
