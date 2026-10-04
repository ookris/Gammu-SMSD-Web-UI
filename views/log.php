<?php /** @var array $f @var ?string $path @var array $lines @var ?int $size @var ?int $mtime @var bool $readable */
$sel = static fn (string $a, string $b) => $a === $b ? ' selected' : '';
?>
<header class="page-head"><div><h1><?= e(t('nav.log')) ?></h1><p class="sub"><?php if ($path): ?><code><?= e($path) ?></code><?= $size !== null ? ' · ' . e(fmt_bytes($size)) . ' · ' . e(t('log.changed', ['ago' => fmt_ago(date('Y-m-d H:i:s', (int) $mtime))])) : '' ?><?php else: ?><?= e(t('log.no_logfile')) ?><?php endif ?></p></div>
<div class="actions"><a href="<?= e(current_url()) ?>" role="button" class="secondary"><?= icon('refresh') ?><?= e(t('modem.refresh')) ?></a></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./" data-autosubmit><input type="hidden" name="p" value="log">
<div class="field"><label for="lines"><?= e(t('log.lines')) ?></label><select id="lines" name="lines"><?php foreach (['100', '500', '2000'] as $n): ?><option value="<?= $n ?>"<?= $sel($n, $f['lines']) ?>><?= e(t('log.last_n', ['n' => $n])) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="order"><?= e(t('log.order')) ?></label><select id="order" name="order"><option value="desc"<?= $sel('desc', $f['order']) ?>><?= e(t('log.newest_top')) ?></option><option value="asc"<?= $sel('asc', $f['order']) ?>><?= e(t('log.newest_bottom')) ?></option></select></div>
<div class="field grow"><label for="q"><?= e(t('log.filter')) ?></label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="<?= e(t('log.filter_ph')) ?>"></div>
<label><input type="checkbox" name="hl" value="1"<?= $f['hl'] !== '' ? ' checked' : '' ?>> <span><?= e(t('log.highlight')) ?></span></label>
<label><input type="checkbox" name="auto" value="1"<?= $f['auto'] !== '' ? ' checked' : '' ?>> <span><?= e(t('log.auto')) ?></span></label>
</form>
</section>
<div class="gap-lg"></div>
<?php if (!$readable): ?>
<?= alert('warn', t('log.unreadable'), $path ? t('log.unreadable_text', ['path' => $path]) : t('log.no_logfile_text'), '', false) ?>
<?php else: ?>
<?= view('log-lines', get_defined_vars()) ?>
<p class="muted small gap-lg"><?= e(t('log.note')) ?></p>
<?php endif ?>
