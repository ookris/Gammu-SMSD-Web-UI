<?php /** @var array $plan @var bool $oob */ ?>
<div id="compose-vars"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?php if ($plan['vars_missing'] > 0): ?><?= alert('warn', '', $plan['vars_missing'] . ' ' . plural($plan['vars_missing'], 'odbiorca', 'odbiorców', 'odbiorców') . ' spoza książki – {imie} i {nazwa} zostaną zastąpione pustym tekstem.') ?><?php endif ?>
</div>
