<?php /** @var ?array $preview */
$cols = ['columns' => '<code>' . e(t('contacts.csv_header')) . '</code>', 'sep' => '<code>|</code>', 'semicolon' => '<code>;</code>', 'comma' => '<code>,</code>'];
?>
<header class="page-head"><div><h1><?= e(t('contacts.import_title')) ?></h1><p class="sub"><?= e(t('contacts.import_sub')) ?> · <a href="<?= e(url('contacts')) ?>"><?= e(t('common.back_to_list')) ?></a></p></div></header>
<?= flashes_html() ?>
<?php if ($preview === null): ?>
<section class="card narrow">
<form class="stack-sm" method="post" action="<?= e(url('contacts')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="field"><label for="csv"><?= e(t('blocklist.csv_file')) ?></label><input id="csv" name="csv" type="file" accept=".csv,text/csv,text/plain" required>
<small><?= t('contacts.import_file_hint', $cols) ?></small></div>
<div><button type="submit"><?= icon('eye') ?><?= e(t('contacts.preview')) ?></button></div>
</form>
</section>
<?php else: ?>
<section class="card">
<header><h2><?= e(t('contacts.preview_title')) ?></h2></header>
<div class="stat-row"><span class="badge badge-ok"><?= e(t('contacts.n_new', ['n' => $preview['new']])) ?></span><span class="badge badge-info"><?= e(t('contacts.n_updated', ['n' => $preview['updated']])) ?></span><span class="badge badge-err"><?= e(t('contacts.n_invalid', ['n' => count($preview['errors'])])) ?></span></div>
<?php if ($preview['errors']): ?><ul class="gap-lg small"><?php foreach (array_slice($preview['errors'], 0, 50) as [$line, $why]): ?><li><?= e(t('contacts.line', ['line' => (int) $line, 'why' => $why])) ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($preview['rows']): ?>
<div class="table-wrap gap-lg"><table><thead><tr><th><?= e(t('contacts.col_name')) ?></th><th><?= e(t('contacts.col_phone')) ?></th><th><?= e(t('contacts.col_groups')) ?></th><th><?= e(t('contacts.col_note')) ?></th></tr></thead><tbody>
<?php foreach (array_slice($preview['rows'], 0, 20) as $r): ?><tr><td><?= e($r['name']) ?></td><td class="mono"><?= e(Phone::format($r['phone'])) ?></td><td><?= e(implode(', ', $r['groups'])) ?></td><td><?= e($r['note']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php if (count($preview['rows']) > 20): ?><p class="muted small"><?= e(t('contacts.more', ['n' => count($preview['rows']) - 20])) ?></p><?php endif ?>
<?php endif ?>
<form class="row gap-lg" method="post" action="<?= e(url('contacts')) ?>"><?= csrf_field() ?>
<button type="submit" name="import_confirm" value="1"<?= $preview['rows'] ? '' : ' disabled' ?>><?= icon('import') ?><?= e(tn('contacts.import_n', count($preview['rows']))) ?></button>
<a href="<?= e(url('contacts', ['import' => 1, 'cancel' => 1])) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></form>
</section>
<?php endif ?>
