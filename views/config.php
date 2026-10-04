<?php /** @var string $tab @var ?GammuConf $conf @var string $path @var ?int $mtime @var array $backups @var ?array $pending */
$tabNames = ['form' => t('config.tab_form'), 'editor' => t('config.tab_editor'), 'backups' => t('config.tab_backups', ['n' => count($backups)]), 'service' => t('config.tab_service')];
?>
<header class="page-head"><div><h1><?= e(t('nav.config')) ?></h1><p class="sub"><code><?= e($path) ?></code><?= $mtime ? ' · ' . e(t('config.last_change', ['date' => fmt_date($mtime, 'H:i')])) : '' ?></p></div>
<?php if ($tab === 'form' || $tab === 'editor'): ?><div class="actions"><button type="submit" form="conf-form" name="action" value="<?= e($tab) ?>"><?= icon('diskette') ?><?= e(t('config.save')) ?></button></div><?php endif ?>
</header>
<nav class="tabs" aria-label="<?= e(t('config.tabs')) ?>"><?php foreach ($tabNames as $k => $label): ?><a href="<?= e(url('config', ['tab' => $k === 'form' ? null : $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?></nav>
<?= flashes_html() ?>
<?php if ($conf === null && $tab !== 'service'): ?>
<?= alert('err', t('config.unreadable', ['path' => $path]), t('config.unreadable_text'), '', false) ?>
<?php else: ?>
<?= view('config-' . $tab, get_defined_vars()) ?>
<?php endif ?>
<?php if ($pending !== null): ?>
<dialog id="confirm-save" aria-labelledby="confirm-save-t" data-autoopen>
<article>
<div class="dlg-head"><span class="dlg-icon"><?= icon('danger-triangle', 'icon-lg') ?></span>
<div><h2 id="confirm-save-t"><?= t('config.confirm_title', ['path' => '<code>' . e($path) . '</code>']) ?></h2>
<p><?= e(t('config.confirm_text')) ?></p></div></div>
<form method="post" action="<?= e(url('config', ['tab' => $pending['tab']])) ?>"><?= csrf_field() ?>
<input type="hidden" name="token" value="<?= e($pending['token']) ?>">
<div class="dlg-body">
<p class="small"><strong><?= e(t('config.changes')) ?></strong> – <?= e(tr($pending['note'])) ?></p>
<?php if ($pending['changed'] > 0): ?><pre class="codebox"><?= Diff::html($pending['diff']) ?></pre>
<?php else: ?><p class="muted small"><?= e(t('config.no_visible_changes')) ?></p><?php endif ?>
<?php if ($pending['stale']): ?><?= alert('err', t('config.stale'), t('config.stale_text')) ?><?php endif ?>
<?php if ($pending['validation'] === []): ?><p class="status-line gap-lg"><?= icon('check-circle') ?><?= e(t('config.validation_ok')) ?></p>
<?php else: ?><ul class="health gap-lg"><?php foreach ($pending['validation'] as [$lvl, $txt]): ?><li class="<?= e($lvl) ?>"><?= icon($lvl === 'err' ? 'danger-circle' : 'danger-triangle') ?><span><?= e($txt) ?></span></li><?php endforeach ?></ul><?php endif ?>
<fieldset class="gap-lg"><legend><?= e(t('config.after')) ?></legend>
<label><input type="radio" name="after" value="reload" checked> <?= e(t('config.after_reload')) ?></label>
<label><input type="radio" name="after" value="restart"> <?= e(t('config.after_restart')) ?></label>
<label><input type="radio" name="after" value="none"> <?= e(t('config.after_none')) ?></label></fieldset>
</div>
<footer><button type="submit" name="action" value="discard" class="secondary outline" formnovalidate><?= e(t('common.cancel')) ?></button>
<button type="submit" name="action" value="confirm" class="btn-warn"<?= $pending['blocked'] || $pending['stale'] ? ' disabled' : '' ?>><?= e(t('config.save_confirm')) ?></button></footer>
</form>
</article>
</dialog>
<?php endif ?>
