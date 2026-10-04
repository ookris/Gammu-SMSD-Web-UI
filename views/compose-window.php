<?php /** @var array $plan @var bool $oob */ ?>
<div id="compose-window" class="stack-sm"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?php if ($plan['multi']): ?>
<p class="row muted small"><?= icon('clock-circle') ?><?= e($plan['per_min'] > 0 ? t('compose.throttled', ['n' => $plan['per_min'], 'time' => fmt_duration(max(60, $plan['duration']))]) : t('compose.not_throttled')) ?></p>
<?php endif ?>
<?php if ($plan['multi'] && $plan['window'] !== null && $plan['delayed'] && $plan['send_at'] === null): ?>
<?= alert('info', t('compose.window_wait', ['now' => date('H:i'), 'start' => Compose::startLabel($plan)]), t('compose.window_wait_text', ['from' => substr($plan['window'][0], 0, 5), 'to' => substr($plan['window'][1], 0, 5)])) ?>
<?php endif ?>
</div>
