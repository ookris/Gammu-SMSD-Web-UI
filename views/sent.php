<?php /** @var array $rows @var int $total @var int $page @var array $f @var ?string $last @var ?array $lastMeta @var ?array $lastStats */
$opt = static fn (string $v, string $cur) => '<option value="' . e($v) . '"' . ($v === $cur ? ' selected' : '') . '>';
?>
<header class="page-head"><div><h1>Wysłane</h1><p class="sub">Historia i kolejka wiadomości wychodzących</p></div>
<div class="actions"><a href="<?= e(url('compose')) ?>" role="button"><?= icon('add-circle') ?>Nowa wiadomość</a></div></header>
<?= flashes_html() ?>
<?php if ($last !== null && $f === array_fill_keys(array_keys($f), '')): ?>
<section class="card">
<header><h2>Ostatnia wysyłka do wielu: <?= e($lastMeta['label'] ?: ($lastStats['total'] . ' odbiorców')) ?> · <?= e(fmt_when($lastMeta['created'])) ?></h2><a href="<?= e(url('batch', ['id' => $last])) ?>"><strong>Raport wysyłki</strong></a></header>
<div class="row"><span class="badge badge-ok"><?= $lastStats['delivered'] ?> doręczone</span><span class="badge badge-info"><?= $lastStats['sent'] ?> wysłane</span><span class="badge"><?= $lastStats['scheduled'] ?> zaplanowane</span><span class="badge badge-warn"><?= $lastStats['queued'] ?> w kolejce</span><span class="badge badge-err"><?= $lastStats['failed'] ?> błędy</span>
<span class="muted small"><?= $lastStats['total'] ?> odbiorców · <?= $lastMeta['parts'] ?> SMS każdy<?= $lastMeta['per_min'] ? ' · dławienie ' . (int) $lastMeta['per_min'] . ' SMS/min' : '' ?></span></div>
</section>
<div class="gap-lg"></div>
<?php endif ?>
<section class="card flush">
<form class="toolbar" method="get" action="./">
<input type="hidden" name="p" value="sent"><?php if ($f['batch'] !== ''): ?><input type="hidden" name="batch" value="<?= e($f['batch']) ?>"><?php endif ?>
<div class="field grow"><label for="q">Szukaj</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="odbiorca, numer lub treść"></div>
<div class="field"><label for="status">Status</label><select id="status" name="status">
<?= $opt('', $f['status']) ?>Wszystkie</option>
<?php foreach (['scheduled' => 'Zaplanowana', 'queued' => 'W kolejce', 'retrying' => 'Ponawiana', 'sent' => 'Wysłana', 'delivered' => 'Doręczona', 'undelivered' => 'Niedoręczona', 'failed' => 'Błąd', 'cancelled' => 'Anulowana'] as $k => $v): ?>
<?= $opt($k, $f['status']) ?><?= e($v) ?></option>
<?php endforeach ?></select></div>
<div class="field"><label for="source">Źródło</label><select id="source" name="source">
<?= $opt('', $f['source']) ?>Wszystkie</option><?= $opt('gui', $f['source']) ?>Panel</option><?= $opt('external', $f['source']) ?>Zewnętrzne</option><?= $opt('api', $f['source']) ?>API</option></select></div>
<div class="field"><label for="from">Od</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to">Do</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<button type="submit" class="secondary"><?= icon('filter') ?>Filtruj</button>
</form>
<?php if ($rows === []): ?>
<?= array_filter($f) ? Ui::empty('magnifer', 'Brak wyników', 'Zmień filtry.', '<a href="' . e(url('sent')) . '" role="button" class="secondary outline">Wyczyść filtry</a>')
    : Ui::empty('inbox-out', 'Nie wysłano jeszcze żadnej wiadomości', 'Wiadomości z panelu i wysłane innymi drogami pojawią się tutaj.', '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . 'Nowa wiadomość</a>') ?>
<?php else: ?>
<form method="post" action="<?= e(url('sent')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong>Zaznaczono <span data-bulk-count>0</span></strong>
<button type="submit" name="action" value="retry" class="secondary outline btn-sm"><?= icon('restart') ?>Ponów</button>
<button type="submit" name="action" value="cancel" class="secondary outline btn-sm"<?= Ui::confirm('Anulować zaznaczone wiadomości?', 'Wiadomości, które Gammu właśnie wysyła, nie zostaną zatrzymane.', 'Anuluj wysyłkę') ?>>Anuluj</button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm('Usunąć zaznaczone z historii?', 'Wiadomości w kolejce zostaną pominięte – najpierw je anuluj.', 'Usuń') ?>><?= icon('trash-bin-trash') ?>Usuń z historii</button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="Zaznacz wszystkie"></th><th scope="col">Data</th><th scope="col">Odbiorca</th><th scope="col">Treść</th><th scope="col">Części</th><th scope="col">Status</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($rows as $m): $pending = in_array($m['status'], ['scheduled', 'queued'], true); ?>
<tr>
<td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="Zaznacz wiersz"></td>
<td class="nowrap"><?= e(fmt_when($m['created_at'])) ?></td>
<td><?= Ui::who($m['phone'], false) ?><?= $m['source'] === 'external' ? ' <span class="badge">zewnętrzna</span>' : '' ?><?= $m['modem'] ? '<span class="sub">' . e($m['modem']) . '</span>' : '' ?></td>
<td><span class="truncate" title="<?= e($m['body']) ?>"><?= e(Ui::snippet($m['body'], 70)) ?></span></td>
<td><?= (int) $m['parts'] ?></td>
<td><?= Ui::status($m) ?></td>
<td><div class="cell-actions">
<?php if ($pending): ?><button type="submit" name="cancel" value="<?= (int) $m['id'] ?>" class="icon-btn danger" aria-label="Anuluj" title="Anuluj"<?= Ui::confirm('Anulować wysyłkę?', 'Jeśli Gammu właśnie ją wysyła, nie da się jej zatrzymać.', 'Anuluj wysyłkę') ?>><?= icon('close-circle') ?></button><?php endif ?>
<?php if (in_array($m['status'], ['failed', 'undelivered'], true)): ?><button type="submit" name="retry" value="<?= (int) $m['id'] ?>" class="icon-btn" aria-label="Ponów" title="Ponów"><?= icon('restart') ?></button><?php endif ?>
<?= Ui::iconBtnLink(url('compose', ['copy' => $m['id']]), 'copy', 'Kopiuj treść') ?>
<?php if (!$pending): ?><button type="submit" name="remove" value="<?= (int) $m['id'] ?>" class="icon-btn danger" aria-label="Usuń z historii" title="Usuń z historii"<?= Ui::confirm('Usunąć wiadomość z historii?', '', 'Usuń') ?>><?= icon('trash-bin-trash') ?></button><?php endif ?>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'sent', $f) ?>
<?php endif ?>
</section>
