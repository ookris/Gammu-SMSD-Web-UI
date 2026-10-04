<?php /** @var callable $v @var array $errors @var array $codes @var bool $callsEnabled @var array $system */
$chk = static fn (string $k) => $v($k) === '1' || (is_post() && isset($_POST[$k])) ? ' checked' : '';
$err = static fn (string $k) => isset($errors[$k]) ? '<small class="warn">' . e($errors[$k]) . '</small>' : '';
?>
<header class="page-head"><div><h1><?= e(t('nav.settings')) ?></h1><p class="sub"><?= e(t('settings.sub')) ?></p></div>
<div class="actions"><button type="submit" form="settings-form"><?= icon('diskette') ?><?= e(t('settings.save')) ?></button></div></header>
<?= flashes_html() ?>
<?php if ($errors): ?><?= alert('err', t('settings.invalid')) ?><?php endif ?>
<form id="settings-form" method="post" action="<?= e(url('settings')) ?>" class="split split-480"><?= csrf_field() ?>
<div class="stack">
<section class="card"><header><h2><?= e(t('settings.interface')) ?></h2></header>
<div class="field"><label for="lang"><?= e(t('settings.lang')) ?></label><select id="lang" name="lang" aria-describedby="lang-h">
<?php foreach (LANGS as $code => $name): ?><option value="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === (is_post() ? input('lang') : lang()) ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?></select>
<small id="lang-h"><?= e(t('settings.lang_hint')) ?></small></div>
</section>
<section class="card"><header><h2><?= e(t('settings.sending')) ?></h2></header>
<div class="stack-sm">
<div class="grid-2">
<label><input type="checkbox" name="report_default" value="1"<?= $chk('report_default') ?>> <span><?= e(t('settings.report_default')) ?></span></label>
<label><input type="checkbox" name="translit_default" value="1"<?= $chk('translit_default') ?>> <span><?= e(t('settings.translit_default')) ?></span></label></div>
<div class="grid-2">
<div class="field"><label for="throttle"><?= e(t('settings.throttle')) ?></label>
<input id="throttle" aria-describedby="throttle-h" name="throttle_per_min" type="number" value="<?= e($v('throttle_per_min')) ?>" min="0" max="60">
<small id="throttle-h"><?= e(t('settings.throttle_hint')) ?></small><?= $err('throttle_per_min') ?></div>
<fieldset><legend><?= e(t('settings.window')) ?></legend>
<label><input type="checkbox" name="window_enabled" value="1"<?= $chk('window_enabled') ?>> <span><?= e(t('settings.window_on')) ?></span></label>
<div class="inline-pair"><label for="win-from" class="visually-hidden"><?= e(t('settings.from')) ?></label><input id="win-from" name="window_from" type="time" value="<?= e($v('window_from')) ?>"><span>–</span>
<label for="win-to" class="visually-hidden"><?= e(t('settings.to')) ?></label><input id="win-to" name="window_to" type="time" value="<?= e($v('window_to')) ?>"></div>
<small<?= isset($errors['window']) ? ' class="warn"' : '' ?>><?= e($errors['window'] ?? t('settings.window_hint')) ?></small></fieldset>
</div></div></section>
<section class="card"><header><h2><?= e(t('settings.ussd')) ?></h2></header>
<div class="stack-sm" id="ussd-rows">
<?php foreach ($codes ?: [['name' => '', 'code' => '']] as $c): ?>
<div class="row"><input name="ussd_name[]" aria-label="<?= e(t('settings.ussd_name')) ?>" value="<?= e($c['name']) ?>" class="grow-1" placeholder="<?= e(t('settings.ussd_name_ph')) ?>"><input name="ussd_code[]" aria-label="<?= e(t('settings.ussd_code')) ?>" value="<?= e($c['code']) ?>" class="mono-input code-input" placeholder="*101#">
<button type="button" class="icon-btn danger" aria-label="<?= e(t('settings.ussd_remove')) ?>" title="<?= e(t('settings.ussd_remove')) ?>" data-remove-row><?= icon('trash-bin-trash') ?></button></div>
<?php endforeach ?>
</div>
<?= $err('ussd') ?>
<template id="ussd-row-tpl"><div class="row"><input name="ussd_name[]" aria-label="<?= e(t('settings.ussd_name')) ?>" class="grow-1" placeholder="<?= e(t('settings.ussd_name_ph')) ?>"><input name="ussd_code[]" aria-label="<?= e(t('settings.ussd_code')) ?>" class="mono-input code-input" placeholder="*101#">
<button type="button" class="icon-btn danger" aria-label="<?= e(t('settings.ussd_remove')) ?>" title="<?= e(t('settings.ussd_remove')) ?>" data-remove-row><?= icon('trash-bin-trash') ?></button></div></template>
<div class="gap-lg"><button type="button" class="secondary outline btn-sm" data-add-row="ussd-row-tpl" data-target="ussd-rows"><?= icon('add-circle') ?><?= e(t('settings.ussd_add')) ?></button></div>
</section>
<section class="card"><header><h2><?= e(t('settings.calls')) ?></h2></header>
<label><input type="checkbox" name="calls_enabled" value="1"<?= ($callsEnabled && !is_post()) || isset($_POST['calls_enabled']) ? ' checked' : '' ?>> <span><?= e(t('settings.calls_on')) ?><span class="check-sub"><?= e(t('settings.calls_hint')) ?></span></span></label>
</section>
<section class="card"><header><h2><?= e(t('settings.session')) ?></h2></header>
<div class="grid-3">
<div class="field"><label for="session_hours"><?= e(t('settings.session_hours')) ?></label><input id="session_hours" name="session_hours" type="number" min="1" max="720" value="<?= e($v('session_hours')) ?>"><?= $err('session_hours') ?></div>
<div class="field"><label for="backup_keep"><?= e(t('settings.backup_keep')) ?></label><input id="backup_keep" name="backup_keep" type="number" min="1" max="500" value="<?= e($v('backup_keep')) ?>"><?= $err('backup_keep') ?></div>
<div class="field"><label for="cleanup_days"><?= e(t('settings.cleanup_days')) ?></label><input id="cleanup_days" aria-describedby="cleanup-h" name="cleanup_days" type="number" min="0" max="3650" value="<?= e($v('cleanup_days')) ?>"><small id="cleanup-h"><?= e(t('settings.cleanup_hint')) ?></small><?= $err('cleanup_days') ?></div>
</div></section>
</div>
<section class="card"><header><h2><?= e(t('settings.system')) ?></h2><span class="badge"><?= e(t('settings.readonly')) ?></span></header>
<dl class="kv kv-170"><?php foreach ($system as $k => $val): ?><dt><?= e($k) ?></dt><dd><span class="mono"><?= e($val) ?></span></dd><?php endforeach ?></dl>
<p class="muted small gap-lg"><?= t('settings.system_hint', ['file' => '<code>config/config.php</code>']) ?></p>
</section>
</form>
