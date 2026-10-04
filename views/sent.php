<?php /** @var array $rows @var int $total @var int $page @var array $f @var ?string $last @var ?array $lastMeta @var ?array $lastStats */
$opt = static fn (string $v, string $cur) => '<option value="' . e($v) . '"' . ($v === $cur ? ' selected' : '') . '>';
?>
<header class="page-head"><div><h1><?= e(t('nav.sent')) ?></h1><p class="sub"><?= e(t('sent.sub')) ?></p></div>
<div class="actions"><a href="<?= e(url('compose')) ?>" role="button"><?= icon('add-circle') ?><?= e(t('nav.compose')) ?></a></div></header>
<?= flashes_html() ?>
<?php if ($last !== null && $f === array_fill_keys(array_keys($f), '')): ?>
<section class="card">
<header><h2><?= e(t('sent.last_batch', ['label' => $lastMeta['label'] ?: tn('sent.recipients', (int) $lastStats['total']), 'when' => fmt_when($lastMeta['created'])])) ?></h2><a href="<?= e(url('batch', ['id' => $last])) ?>"><strong><?= e(t('sent.batch_report')) ?></strong></a></header>
<div class="row"><span class="badge badge-ok"><?= e(t('sent.n_delivered', ['n' => $lastStats['delivered']])) ?></span><span class="badge badge-info"><?= e(t('sent.n_sent', ['n' => $lastStats['sent']])) ?></span><span class="badge"><?= e(t('sent.n_scheduled', ['n' => $lastStats['scheduled']])) ?></span><span class="badge badge-warn"><?= e(t('sent.n_queued', ['n' => $lastStats['queued']])) ?></span><span class="badge badge-err"><?= e(t('sent.n_failed', ['n' => $lastStats['failed']])) ?></span>
<span class="muted small"><?= e(tn('sent.recipients', (int) $lastStats['total'])) ?> · <?= e(t('sent.each', ['n' => $lastMeta['parts']])) ?><?= $lastMeta['per_min'] ? ' · ' . e(t('sent.throttle', ['n' => (int) $lastMeta['per_min']])) : '' ?></span></div>
</section>
<div class="gap-lg"></div>
<?php endif ?>
<section class="card flush">
<form class="toolbar" method="get" action="./">
<input type="hidden" name="p" value="sent"><?php foreach (['batch', 'sent_from', 'changed_from'] as $k): if ($f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k]) ?>"><?php endif; endforeach ?>
<div class="field grow"><label for="q"><?= e(t('common.search')) ?></label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="<?= e(t('sent.search_ph')) ?>"></div>
<div class="field"><label for="status"><?= e(t('common.status')) ?></label><select id="status" name="status">
<?= $opt('', $f['status']) ?><?= e(t('common.all')) ?></option>
<optgroup label="<?= e(t('sent.grouped')) ?>"><?php foreach (['pending', 'done', 'problem'] as $k): ?>
<?= $opt($k, $f['status']) ?><?= e(t('sent.f_' . $k)) ?></option>
<?php endforeach ?></optgroup>
<optgroup label="<?= e(t('common.status')) ?>"><?php foreach (['scheduled', 'queued', 'retrying', 'sent', 'delivered', 'undelivered', 'failed', 'cancelled'] as $k): ?>
<?= $opt($k, $f['status']) ?><?= e(t('status.' . $k)) ?></option>
<?php endforeach ?></optgroup></select></div>
<div class="field"><label for="source"><?= e(t('sent.source')) ?></label><select id="source" name="source">
<?= $opt('', $f['source']) ?><?= e(t('common.all')) ?></option><?php foreach (['gui', 'external', 'api'] as $k): ?><?= $opt($k, $f['source']) ?><?= e(t('sent.src_' . $k)) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="from"><?= e(t('common.from')) ?></label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to"><?= e(t('common.to')) ?></label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<button type="submit" class="secondary"><?= icon('filter') ?><?= e(t('common.filter')) ?></button>
</form>
<?php if ($f['sent_from'] !== '' || $f['changed_from'] !== ''): ?>
<p class="card-note"><?= e($f['sent_from'] !== '' ? t('sent.sent_from', ['date' => fmt_date((int) ts($f['sent_from']))]) : t('sent.changed_from', ['date' => fmt_date((int) ts($f['changed_from']))])) ?> · <a href="<?= e(url('sent', array_diff_key($f, ['sent_from' => 1, 'changed_from' => 1]))) ?>"><?= e(t('sent.no_date_limit')) ?></a></p>
<?php endif ?>
<?php if ($rows === []): ?>
<?= array_filter($f) ? Ui::empty('magnifer', t('common.no_results'), t('common.change_filters'), '<a href="' . e(url('sent')) . '" role="button" class="secondary outline">' . e(t('common.clear_filters')) . '</a>')
    : Ui::empty('inbox-out', t('sent.empty'), t('sent.empty_text'), '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . e(t('nav.compose')) . '</a>') ?>
<?php else: ?>
<form method="post" action="<?= e(url('sent')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong><?= t('common.selected', ['n' => '<span data-bulk-count>0</span>']) ?></strong>
<button type="submit" name="action" value="retry" class="secondary outline btn-sm"><?= icon('restart') ?><?= e(t('common.retry')) ?></button>
<button type="submit" name="action" value="cancel" class="secondary outline btn-sm"<?= Ui::confirm(t('sent.cancel_selected'), t('sent.cancel_selected_text'), t('sent.cancel_ok')) ?>><?= e(t('sent.cancel')) ?></button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm(t('sent.delete_selected'), t('sent.delete_selected_text'), t('common.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('sent.delete_history')) ?></button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="<?= e(t('common.select_all')) ?>"></th><th scope="col"><?= e(t('common.date')) ?></th><th scope="col"><?= e(t('common.recipient')) ?></th><th scope="col"><?= e(t('common.body')) ?></th><th scope="col"><?= e(t('common.parts')) ?></th><th scope="col"><?= e(t('common.status')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $m): $pending = in_array($m['status'], ['scheduled', 'queued'], true); ?>
<tr>
<td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="<?= e(t('common.select_row')) ?>"></td>
<td class="nowrap"><?= e(fmt_when($m['created_at'])) ?></td>
<td><?= Ui::who($m['phone'], false) ?><?= $m['source'] === 'external' ? ' <span class="badge">' . e(t('sent.external')) . '</span>' : '' ?><?= $m['modem'] ? '<span class="sub">' . e($m['modem']) . '</span>' : '' ?></td>
<td><span class="truncate" title="<?= e($m['body']) ?>"><?= e(Ui::snippet($m['body'], 70)) ?></span></td>
<td><?= (int) $m['parts'] ?></td>
<td><?= Ui::status($m) ?></td>
<td><div class="cell-actions">
<?php if ($pending): ?><button type="submit" name="cancel" value="<?= (int) $m['id'] ?>" class="icon-btn danger" aria-label="<?= e(t('sent.cancel')) ?>" title="<?= e(t('sent.cancel')) ?>"<?= Ui::confirm(t('sent.cancel_one'), t('sent.cancel_one_text'), t('sent.cancel_ok')) ?>><?= icon('close-circle') ?></button><?php endif ?>
<?php if (in_array($m['status'], ['failed', 'undelivered'], true)): ?><button type="submit" name="retry" value="<?= (int) $m['id'] ?>" class="icon-btn" aria-label="<?= e(t('common.retry')) ?>" title="<?= e(t('common.retry')) ?>"><?= icon('restart') ?></button><?php endif ?>
<?= Ui::iconBtnLink(url('compose', ['copy' => $m['id']]), 'copy', t('common.copy_text')) ?>
<?php if (!$pending): ?><button type="submit" name="remove" value="<?= (int) $m['id'] ?>" class="icon-btn danger" aria-label="<?= e(t('sent.delete_history')) ?>" title="<?= e(t('sent.delete_history')) ?>"<?= Ui::confirm(t('sent.delete_one'), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button><?php endif ?>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'sent', $f) ?>
<?php endif ?>
</section>
