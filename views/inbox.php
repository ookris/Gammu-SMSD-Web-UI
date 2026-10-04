<?php /** @var array $rows @var int $total @var int $page @var array $f @var array $stats @var array $blocked */ ?>
<header class="page-head"><div><h1>Odebrane</h1><p class="sub"><?= (int) $stats['n'] ?> <?= plural((int) $stats['n'], 'wiadomość', 'wiadomości', 'wiadomości') ?> · <?= (int) $stats['unread'] ?> <?= plural((int) $stats['unread'], 'nieprzeczytana', 'nieprzeczytane', 'nieprzeczytanych') ?></p></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./">
<input type="hidden" name="p" value="inbox">
<div class="field grow"><label for="q">Szukaj</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="nadawca, numer lub treść"></div>
<div class="field"><label for="from">Od</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to">Do</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<label><input type="checkbox" name="unread" value="1"<?= $f['unread'] !== '' ? ' checked' : '' ?>> <span>Tylko nieprzeczytane</span></label>
<button type="submit" class="secondary"><?= icon('filter') ?>Filtruj</button>
</form>
<?php if ($rows === []): ?>
<?= array_filter($f) ? Ui::empty('magnifer', 'Brak wyników', 'Zmień frazę albo zakres dat.', '<a href="' . e(url('inbox')) . '" role="button" class="secondary outline">Wyczyść filtry</a>')
    : Ui::empty('inbox-in', 'Nie ma jeszcze odebranych wiadomości', 'Pojawią się tu, gdy modem odbierze SMS.') ?>
<?php else: ?>
<form method="post" action="<?= e(url('inbox')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong>Zaznaczono <span data-bulk-count>0</span></strong>
<button type="submit" name="action" value="read" class="secondary outline btn-sm"><?= icon('check-circle') ?>Oznacz jako przeczytane</button>
<button type="submit" name="action" value="unread" class="secondary outline btn-sm">Oznacz jako nieprzeczytane</button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm('Usunąć zaznaczone wiadomości?', 'Wiadomości znikną z panelu (także z rozmów).', 'Usuń') ?>><?= icon('trash-bin-trash') ?>Usuń</button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="Zaznacz wszystkie"></th><th scope="col">Odebrano</th><th scope="col">Nadawca</th><th scope="col">Treść</th><th scope="col">Modem</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($rows as $m): $alpha = Phone::isAlpha($m['phone']); $isBlocked = in_array($m['phone'], $blocked, true); ?>
<tr<?= $m['is_read'] ? '' : ' class="unread"' ?>>
<td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="Zaznacz wiersz"></td>
<td class="nowrap"><?= e(fmt_when($m['received_at'])) ?></td>
<td><?= Ui::who($m['phone']) ?><?= $isBlocked ? ' <span class="badge badge-err">zablokowany</span>' : '' ?></td>
<td><?= nl2br(e($m['body'])) ?><?php if ($m['incomplete'] || $m['flash']): ?><span class="tags"><?php if ($m['incomplete']): ?><span class="badge badge-warn">niekompletna</span><?php endif ?><?php if ($m['flash']): ?><span class="badge badge-info">Flash</span><?php endif ?></span><?php endif ?></td>
<td><?= e($m['modem'] ?? '—') ?></td>
<td><div class="cell-actions">
<?php if (!$alpha): ?><?= Ui::iconBtnLink(url('threads', ['phone' => $m['phone']]), 'chat-round-line', 'Odpowiedz') ?><?php endif ?>
<?= Ui::iconBtnLink(url('compose', ['forward' => $m['id']]), 'forward', 'Przekaż dalej') ?>
<?php if (!$isBlocked): ?><button type="submit" name="block" value="<?= e($m['phone']) ?>" class="icon-btn danger" aria-label="Zablokuj nadawcę" title="Zablokuj nadawcę"<?= Ui::confirm('Zablokować ' . Contacts::display($m['phone']) . '?', 'SMS od tego nadawcy nie będą trafiać do panelu – Gammu usunie je z modemu.', 'Zablokuj') ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'inbox', $f) ?>
<?php endif ?>
</section>
