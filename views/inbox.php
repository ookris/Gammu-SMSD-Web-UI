<?php /** @var array $rows @var int $total @var int $page @var array $f @var array $stats @var array $blocked */ ?>
<header class="page-head"><div><h1><?= e(t('nav.inbox')) ?></h1><p class="sub"><?= e(tn('common.messages', (int) $stats['n'])) ?> · <?= e(tn('common.unread', (int) $stats['unread'])) ?></p></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./">
<input type="hidden" name="p" value="inbox">
<div class="field grow"><label for="q"><?= e(t('common.search')) ?></label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="<?= e(t('inbox.search_ph')) ?>"></div>
<div class="field"><label for="from"><?= e(t('common.from')) ?></label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to"><?= e(t('common.to')) ?></label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<label><input type="checkbox" name="unread" value="1"<?= $f['unread'] !== '' ? ' checked' : '' ?>> <span><?= e(t('inbox.only_unread')) ?></span></label>
<button type="submit" class="secondary"><?= icon('filter') ?><?= e(t('common.filter')) ?></button>
</form>
<?php if ($rows === []): ?>
<?= array_filter($f) ? Ui::empty('magnifer', t('common.no_results'), t('inbox.change_filters'), '<a href="' . e(url('inbox')) . '" role="button" class="secondary outline">' . e(t('common.clear_filters')) . '</a>')
    : Ui::empty('inbox-in', t('inbox.empty'), t('inbox.empty_text')) ?>
<?php else: ?>
<form method="post" action="<?= e(url('inbox')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong><?= t('common.selected', ['n' => '<span data-bulk-count>0</span>']) ?></strong>
<button type="submit" name="action" value="read" class="secondary outline btn-sm"><?= icon('check-circle') ?><?= e(t('inbox.mark_read')) ?></button>
<button type="submit" name="action" value="unread" class="secondary outline btn-sm"><?= e(t('inbox.mark_unread')) ?></button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm(t('inbox.delete_selected'), t('inbox.delete_selected_text'), t('common.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('common.delete')) ?></button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="<?= e(t('common.select_all')) ?>"></th><th scope="col"><?= e(t('inbox.received')) ?></th><th scope="col"><?= e(t('inbox.sender')) ?></th><th scope="col"><?= e(t('common.body')) ?></th><th scope="col"><?= e(t('inbox.modem')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $m): $alpha = Phone::isAlpha($m['phone']); $isBlocked = in_array($m['phone'], $blocked, true); ?>
<tr<?= $m['is_read'] ? '' : ' class="unread"' ?>>
<td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="<?= e(t('common.select_row')) ?>"></td>
<td class="nowrap"><?= e(fmt_when($m['received_at'])) ?></td>
<td><?= Ui::who($m['phone']) ?><?= $isBlocked ? ' <span class="badge badge-err">' . e(t('inbox.blocked')) . '</span>' : '' ?></td>
<td><?= nl2br(e($m['body'])) ?><?php if ($m['incomplete'] || $m['flash']): ?><span class="tags"><?php if ($m['incomplete']): ?><span class="badge badge-warn"><?= e(t('inbox.incomplete')) ?></span><?php endif ?><?php if ($m['flash']): ?><span class="badge badge-info">Flash</span><?php endif ?></span><?php endif ?></td>
<td><?= e($m['modem'] ?? '—') ?></td>
<td><div class="cell-actions">
<?php if (!$alpha): ?><?= Ui::iconBtnLink(url('threads', ['phone' => $m['phone']]), 'chat-round-line', t('inbox.reply')) ?><?php endif ?>
<?= Ui::iconBtnLink(url('compose', ['forward' => $m['id']]), 'forward', t('inbox.forward')) ?>
<?php if (!$isBlocked): ?><button type="submit" name="block" value="<?= e($m['phone']) ?>" class="icon-btn danger" aria-label="<?= e(t('inbox.block_sender')) ?>" title="<?= e(t('inbox.block_sender')) ?>"<?= Ui::confirm(t('inbox.block_q', ['who' => Contacts::display($m['phone'])]), t('inbox.block_text'), t('inbox.block')) ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'inbox', $f) ?>
<?php endif ?>
</section>
