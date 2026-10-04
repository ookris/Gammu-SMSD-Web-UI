<?php /** @var array $plan @var bool $oob */ ?>
<div id="compose-vars"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?php if ($plan['vars_missing'] > 0): ?><?= alert('warn', '', tn('compose.vars_missing', $plan['vars_missing'])) ?><?php endif ?>
</div>
