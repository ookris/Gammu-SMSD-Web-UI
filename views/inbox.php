<?php /** @var string $phone @var array $list @var array $f @var array $blockedList @var ?array $contact @var array $groups @var array $items @var string $version @var bool $blocked @var array $stats */ ?>
<header class="page-head"><div><h1><?= e(t('nav.inbox')) ?></h1><p class="sub"><?= e(tn('threads.count', (int) $stats['threads'])) ?> · <?= e(tn('common.unread', (int) $stats['unread'])) ?></p></div>
<div class="actions"><a href="<?= e(url('compose')) ?>" role="button"><?= icon('add-circle') ?><?= e(t('nav.compose')) ?></a></div></header>
<?= flashes_html() ?>
<?php if ($list === [] && !array_filter($f)): ?>
<section class="card"><?= Ui::empty('chat-round-line', t('threads.empty'), t('threads.empty_text'), '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . e(t('nav.compose')) . '</a>') ?></section>
<?php else: ?>
<div class="threads<?= $phone !== '' ? ' is-open' : '' ?>">
<div class="thread-list">
<form class="search" method="get" action="./"><input type="hidden" name="p" value="inbox">
<?php if ($phone !== ''): ?><input type="hidden" name="phone" value="<?= e($phone) ?>"><?php endif ?>
<input id="tq" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="<?= e(t('threads.search_ph')) ?>" aria-label="<?= e(t('threads.search')) ?>">
<details class="filters"<?= array_filter([$f['from'], $f['to'], $f['unread'], $f['dir']]) ? ' open' : '' ?>><summary><?= icon('filter') ?><?= e(t('common.filter')) ?></summary>
<div class="field"><label for="from"><?= e(t('common.from')) ?></label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to"><?= e(t('common.to')) ?></label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<div class="field"><label for="dir"><?= e(t('inbox.dir')) ?></label><select id="dir" name="dir">
<?php foreach (['' => 'inbox.dir_all', 'in' => 'inbox.dir_in', 'out' => 'inbox.dir_out'] as $k => $key): ?><option value="<?= $k ?>"<?= $f['dir'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<label><input type="checkbox" name="unread" value="1"<?= $f['unread'] !== '' ? ' checked' : '' ?>> <span><?= e(t('inbox.only_unread')) ?></span></label>
<div class="row"><button type="submit" class="secondary btn-sm"><?= e(t('common.filter')) ?></button>
<?php if (array_filter($f)): ?><a href="<?= e(url('inbox', $phone !== '' ? ['phone' => $phone] : [])) ?>" role="button" class="secondary outline btn-sm"><?= e(t('common.clear_filters')) ?></a><?php endif ?></div>
</details></form>
<form method="post" action="<?= e(url('inbox')) ?>" class="thread-bulk"><?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong><?= t('common.selected', ['n' => '<span data-bulk-count>0</span>']) ?></strong>
<button type="submit" name="bulk" value="read" class="secondary outline btn-sm"><?= icon('check-circle') ?><?= e(t('inbox.mark_read')) ?></button>
<button type="submit" name="bulk" value="unread" class="secondary outline btn-sm"><?= e(t('inbox.mark_unread')) ?></button>
<button type="submit" name="bulk" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm(t('inbox.delete_threads'), t('inbox.delete_threads_text'), t('common.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('common.delete')) ?></button></div>
<ul>
<?php foreach ($list as $t): $label = Contacts::display($t['phone']); ?>
<li<?= $t['unread'] > 0 ? ' class="unread"' : '' ?>><input type="checkbox" name="phones[]" value="<?= e($t['phone']) ?>" aria-label="<?= e(t('common.select_row')) ?>"><a href="<?= e(url('inbox', ['phone' => $t['phone']])) ?>"<?= $t['phone'] === $phone ? ' aria-current="page"' : '' ?>>
<span class="avatar" aria-hidden="true"><?= e(Contacts::initials(Contacts::name($t['phone']) ?? (Phone::isAlpha($t['phone']) ? $t['phone'] : ''))) ?></span>
<span class="name"><?= e($label) ?><?= in_array($t['phone'], $blockedList, true) ? ' <span class="badge badge-err">' . e(t('inbox.blocked')) . '</span>' : '' ?></span><span class="when"><?= e(fmt_short($t['received_at'] ?? $t['created_at'])) ?></span>
<span class="snippet"><?= e(Ui::snippet((string) (($t['match'] ?? null) ?? $t['body']), 60)) ?></span><?= $t['unread'] > 0 ? '<span class="nav-count">' . $t['unread'] . '</span>' : '<span></span>' ?></a></li>
<?php endforeach ?>
<?php if ($list === []): ?><li class="muted small"><?= Ui::empty('magnifer', t('common.no_results'), t('inbox.change_filters')) ?></li><?php endif ?>
</ul>
</form>
</div>
<?php if ($phone === ''): ?>
<section class="thread-view"><?= Ui::empty('chat-round-line', t('threads.choose'), t('threads.choose_text')) ?></section>
<?php else: $alpha = Phone::isAlpha($phone); ?>
<section class="thread-view" aria-label="<?= e(t('threads.with', ['who' => Contacts::display($phone)])) ?>">
<header class="thread-head">
<a href="<?= e(url('inbox')) ?>" class="icon-btn" aria-label="<?= e(t('threads.back')) ?>" title="<?= e(t('threads.back')) ?>"><?= icon('alt-arrow-left') ?></a>
<span class="avatar" aria-hidden="true"><?= e(Contacts::initials($contact['name'] ?? ($alpha ? $phone : ''))) ?></span>
<div><div class="name"><?= e(Contacts::display($phone)) ?><?= $blocked ? ' <span class="badge badge-err">' . e(t('inbox.blocked')) . '</span>' : '' ?></div>
<div class="sub"><?= $contact ? e(Phone::format($phone)) . ($groups ? ' · ' . e(t('threads.groups', ['list' => implode(', ', $groups)])) : '') : e(t($alpha ? 'threads.alpha' : 'threads.unknown')) ?></div></div>
<?php if ($contact): ?><a href="<?= e(url('contact', ['id' => $contact['id']])) ?>"><strong><?= e(t('threads.contact')) ?></strong></a>
<?php elseif (!$alpha): ?><a href="<?= e(url('contact', ['phone' => $phone])) ?>"><strong><?= e(t('threads.add_contact')) ?></strong></a><?php endif ?>
<form method="post" action="<?= e(url('inbox', ['phone' => $phone])) ?>" class="row"><?= csrf_field() ?>
<button type="submit" name="mark_unread" value="1" class="icon-btn" aria-label="<?= e(t('inbox.mark_unread')) ?>" title="<?= e(t('inbox.mark_unread')) ?>"><?= icon('letter') ?></button>
<?php if (!$blocked): ?><button type="submit" name="block" value="1" class="icon-btn danger" aria-label="<?= e(t('threads.block')) ?>" title="<?= e(t('threads.block')) ?>"<?= Ui::confirm(t('inbox.block_q', ['who' => Contacts::display($phone)]), t('threads.block_text'), t('inbox.block')) ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
<button type="submit" name="delete_thread" value="1" class="icon-btn danger" aria-label="<?= e(t('threads.delete')) ?>" title="<?= e(t('threads.delete')) ?>"<?= Ui::confirm(t('threads.delete_q', ['who' => Contacts::display($phone)]), t('threads.delete_text'), t('threads.delete')) ?>><?= icon('trash-bin-trash') ?></button>
</form>
</header>
<?= view('thread-body', ['phone' => $phone, 'items' => $items, 'version' => $version]) ?>
<?php if ($alpha): ?>
<p class="readonly-note"><?= icon('info-circle') ?><?= e(t('threads.alpha_readonly')) ?></p>
<?php else: ?>
<?= view('thread-reply', ['phone' => $phone, 'oob' => false, 'error' => null, 'text' => '']) ?>
<?php endif ?>
</section>
<?php endif ?>
</div>
<?php endif ?>
