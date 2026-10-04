<?php /** @var array $rows @var int $total @var int $page @var array $f @var int $month @var bool $enabled @var array $blocked */ ?>
<header class="page-head"><div><h1><?= e(t('nav.calls')) ?></h1><p class="sub"><?= e(t('calls.sub', ['n' => $month])) ?></p></div></header>
<?= flashes_html() ?>
<?php if ($enabled): ?>
<?= alert('info', t('calls.enabled'), t('calls.enabled_text'), '<a href="' . e(url('settings')) . '">' . e(t('nav.settings')) . '</a>', false) ?>
<?php else: ?>
<?= alert('warn', t('calls.disabled'), t('calls.disabled_text'), '<a href="' . e(url('settings')) . '">' . e(t('nav.settings')) . '</a>', false) ?>
<?php endif ?>
<section class="card flush">
<form class="toolbar" method="get" action="./"><input type="hidden" name="p" value="calls">
<div class="field grow"><label for="q"><?= e(t('calls.search')) ?></label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="<?= e(t('calls.search_ph')) ?>"></div>
<div class="field"><label for="from"><?= e(t('common.from')) ?></label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to"><?= e(t('common.to')) ?></label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<button type="submit" class="secondary"><?= icon('filter') ?><?= e(t('common.filter')) ?></button></form>
<?php if ($rows === []): ?><?= Ui::empty('end-call', t(array_filter($f) ? 'common.no_results' : 'calls.empty'), t(array_filter($f) ? 'common.change_filters' : 'calls.empty_text')) ?><?php else: ?>
<form method="post" action="<?= e(url('calls')) ?>"><?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong><?= t('common.selected', ['n' => '<span data-bulk-count>0</span>']) ?></strong>
<button type="submit" class="btn-danger-outline btn-sm"<?= Ui::confirm(t('calls.delete_selected'), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('common.delete')) ?></button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="<?= e(t('common.select_all')) ?>"></th><th scope="col"><?= e(t('common.date')) ?></th><th scope="col"><?= e(t('calls.col_phone')) ?></th><th scope="col"><?= e(t('inbox.modem')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $c): $hidden = $c['phone'] === ''; $isBlocked = in_array($c['phone'], $blocked, true); $known = !$hidden && Contacts::name($c['phone']) !== null; ?>
<tr><td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="<?= e(t('common.select_row')) ?>"></td>
<td class="nowrap"><?= e(fmt_when($c['received_at'])) ?></td>
<td><?= $hidden ? '<strong>' . e(t(in_array($c['raw_number'], Calls::INVALID, true) ? 'calls.invalid_number' : 'calls.hidden_number')) . '</strong>' : Ui::who($c['phone']) ?><?= $isBlocked ? ' <span class="badge badge-err">' . e(t('inbox.blocked')) . '</span>' : '' ?></td>
<td><?= e($c['modem'] ?: '—') ?></td>
<td><div class="cell-actions">
<?php if (!$hidden): ?><?= Ui::iconBtnLink(url('compose', ['to' => $c['phone']]), 'plain', t('calls.send_sms')) ?>
<?php if (!$known): ?><?= Ui::iconBtnLink(url('contact', ['phone' => $c['phone']]), 'user-plus', t('threads.add_contact')) ?><?php endif ?>
<?php if (!$isBlocked): ?><button type="submit" name="block" value="<?= e($c['phone']) ?>" class="icon-btn danger" aria-label="<?= e(t('inbox.block')) ?>" title="<?= e(t('inbox.block')) ?>"<?= Ui::confirm(t('inbox.block_q', ['who' => Contacts::display($c['phone'])]), t('calls.block_text'), t('inbox.block')) ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
<?php endif ?>
<button type="submit" name="remove" value="<?= (int) $c['id'] ?>" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"><?= icon('trash-bin-trash') ?></button>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'calls', $f) ?>
<?php endif ?>
</section>
