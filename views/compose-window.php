<?php /** @var array $plan @var bool $oob */ ?>
<div id="compose-window" class="stack-sm"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?php if ($plan['multi']): ?>
<p class="row muted small"><?= icon('clock-circle') ?><?= $plan['per_min'] > 0 ? 'Dławienie: ' . $plan['per_min'] . ' SMS/min – wysyłka potrwa ok. ' . e(fmt_duration(max(60, $plan['duration']))) . '.' : 'Bez dławienia – wszystkie wiadomości trafią do kolejki od razu.' ?></p>
<?php endif ?>
<?php if ($plan['multi'] && $plan['window'] !== null && $plan['delayed'] && $plan['send_at'] === null): ?>
<?= alert('info', 'Teraz jest ' . date('H:i') . ' – wysyłka rozpocznie się ' . Compose::startLabel($plan) . '.', '(okno wysyłki do wielu odbiorców: ' . substr($plan['window'][0], 0, 5) . '–' . substr($plan['window'][1], 0, 5) . '). Wiadomości poczekają w kolejce Gammu.') ?>
<?php endif ?>
</div>
