<?php /** @var ?array $op */
$back = $op ? Service::modemBack($op['started']) : null;
$elapsed = $op ? time() - (int) ts($op['started']) : 99;
$waiting = $op !== null && $back === null && $elapsed <= 30;
?>
<div id="modem-wait"<?= $waiting ? ' hx-get="' . e(url('config', ['fragment' => 'modemwait'])) . '" hx-trigger="every 2s" hx-swap="outerHTML"' : '' ?>>
<?php if ($back !== null): ?>
<p class="status-line"><?= icon('check-circle') ?>Modem <?= e($back['ID']) ?> zgłosił się w tabeli <code>phones</code> po <?= max(0, (int) ts($back['UpdatedInDB']) - (int) ts($op['started'])) ?> s</p>
<?php elseif ($waiting): ?>
<div class="ussd-wait" role="status"><?= icon('hourglass') ?><div><strong>Czekam, aż modem zgłosi się w tabeli phones…</strong><div class="muted small"><?= $elapsed ?> s z 30</div></div></div>
<?php elseif ($op !== null): ?>
<p class="status-line warn"><?= icon('danger-triangle') ?>Modem nie zgłosił się w ciągu 30 s – sprawdź log Gammu poniżej.</p>
<?php endif ?>
</div>
