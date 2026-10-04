<?php /** @var array $rows @var string $q @var bool $enabled @var bool $inSync @var ?string $written @var ?string $reload */ ?>
<header class="page-head"><div><h1><?= e(t('nav.blocklist')) ?></h1><p class="sub"><?= t('blocklist.sub', ['param' => '<code>ExcludeNumbersFile</code>']) ?></p></div>
<div class="actions"><a href="#import-blocklist" data-dialog-open="import-blocklist" role="button" class="secondary"><?= icon('upload') ?><?= e(t('blocklist.import_csv')) ?></a><a href="<?= e(url('blocklist', ['export' => 1])) ?>" role="button" class="secondary"><?= icon('download') ?><?= e(t('blocklist.export_csv')) ?></a></div></header>
<?= flashes_html() ?>
<?php if (!$enabled): ?>
<form method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<?= alert('warn', t('blocklist.disabled'), t('blocklist.disabled_text', ['path' => Blocklist::path()]), '<button type="submit" name="enable" value="1" class="btn-sm">' . e(t('blocklist.enable')) . '</button>', false) ?>
</form>
<?php endif ?>
<section class="card">
<header><h2><?= e(t('blocklist.add')) ?></h2></header>
<form class="row row-end" method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<div class="field grow"><label for="bphone"><?= e(t('blocklist.phone')) ?></label><input id="bphone" name="phone" type="text" placeholder="<?= e(t('blocklist.phone_ph')) ?>" required></div>
<div class="field grow"><label for="bnote"><?= e(t('blocklist.note')) ?></label><input id="bnote" name="note" type="text" placeholder="<?= e(t('blocklist.note_ph')) ?>"></div>
<button type="submit"><?= icon('forbidden-circle') ?><?= e(t('inbox.block')) ?></button></form>
<p class="muted small gap-lg"><?= e(t('blocklist.add_hint')) ?></p>
</section>
<div class="gap-lg"></div>
<section class="card flush">
<form class="toolbar" method="get" action="./"><input type="hidden" name="p" value="blocklist">
<div class="field grow"><label for="q"><?= e(t('common.search')) ?></label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(t('blocklist.search_ph')) ?>"></div>
<span class="status-line"><?= icon($inSync ? 'check-circle' : 'danger-triangle') ?><?= e($inSync ? t('blocklist.file_written') . ($written ? ' ' . fmt_when($written) : '') . ($reload === 'ok' ? ' · ' . t('blocklist.reloaded') : '') : t('blocklist.out_of_sync')) ?></span>
</form>
<?php if ($rows === []): ?><?= Ui::empty('forbidden-circle', t($q !== '' ? 'common.no_results' : 'blocklist.empty'), t($q !== '' ? 'threads.change_phrase' : 'blocklist.empty_text')) ?><?php else: ?>
<form method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('blocklist.col_phone')) ?></th><th scope="col"><?= e(t('blocklist.col_contact')) ?></th><th scope="col"><?= e(t('blocklist.col_note')) ?></th><th scope="col"><?= e(t('blocklist.col_added')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td><span class="mono"><?= e(Phone::isAlpha($r['phone']) ? $r['phone'] : Phone::toGammu($r['phone'])) ?></span><?= Phone::isAlpha($r['phone']) ? ' <span class="badge">' . e(t('blocklist.name')) . '</span>' : '' ?></td>
<td><?= $r['contact_id'] ? '<a href="' . e(url('contact', ['id' => $r['contact_id']])) . '">' . e($r['contact_name']) . '</a>' : '<span class="muted">—</span>' ?></td>
<td><?= $r['note'] !== '' ? e($r['note']) : '<span class="muted">—</span>' ?></td><td><?= e(fmt_date((int) ts($r['created_at']))) ?></td>
<td><button type="submit" name="unblock" value="<?= (int) $r['id'] ?>" class="secondary outline btn-sm"><?= e(t('blocklist.unblock')) ?></button></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<div class="pager"><span><?= e(tn('blocklist.count', count($rows))) ?></span><nav aria-label="<?= e(t('ui.pages')) ?>"></nav></div>
<?php endif ?>
</section>
<p class="muted small gap-lg"><?= t('blocklist.footer', ['format' => '<code>' . e(t('blocklist.csv_header')) . '</code>']) ?></p>
<dialog id="import-blocklist" aria-labelledby="import-blocklist-t"><article class="dlg-sm">
<div class="dlg-head"><span class="dlg-icon accent"><?= icon('upload', 'icon-lg') ?></span><div><h2 id="import-blocklist-t"><?= e(t('blocklist.import_title')) ?></h2><p><?= t('blocklist.import_text', ['format' => '<code>' . e(t('blocklist.csv_header')) . '</code>']) ?></p></div></div>
<form method="post" action="<?= e(url('blocklist')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="dlg-body"><input name="csv" type="file" accept=".csv,text/csv,text/plain" required aria-label="<?= e(t('blocklist.csv_file')) ?>"></div>
<footer><button type="button" class="secondary outline" data-dialog-close><?= e(t('common.cancel')) ?></button><button type="submit"><?= icon('import') ?><?= e(t('blocklist.import')) ?></button></footer></form>
</article></dialog>
