<?php /** @var array $groups @var int $edit */ ?>
<header class="page-head"><div><h1>Grupy</h1><p class="sub"><?= count($groups) ?> <?= plural(count($groups), 'grupa', 'grupy', 'grup') ?> · usunięcie grupy nie usuwa kontaktów</p></div></header>
<?= flashes_html() ?>
<section class="card">
<form class="row row-end" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?>
<div class="field grow"><label for="gname">Nazwa nowej grupy</label><input id="gname" name="name" type="text" placeholder="np. Kierowcy" required></div>
<button type="submit"><?= icon('add-circle') ?>Dodaj grupę</button></form>
</section>
<div class="gap-lg"></div>
<section class="card flush">
<?php if ($groups === []): ?><?= Ui::empty('folder-with-files', 'Nie ma jeszcze grup', 'Grupy ułatwiają wysyłkę do wielu osób naraz.') ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col">Nazwa</th><th scope="col">Członkowie</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($groups as $g): ?>
<?php if ($edit === (int) $g['id']): ?>
<tr><td><form class="row" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
<label for="rename" class="visually-hidden">Nowa nazwa</label><input id="rename" name="name" value="<?= e($g['name']) ?>" required autofocus>
<button type="submit" class="btn-sm">Zapisz</button><a href="<?= e(url('groups')) ?>" role="button" class="secondary outline btn-sm">Anuluj</a></form></td>
<td><?= (int) $g['members'] ?></td><td><span class="muted">zmiana nazwy…</span></td></tr>
<?php else: ?>
<tr><td><strong><?= e($g['name']) ?></strong></td><td><?= (int) $g['members'] ?></td>
<td><form class="cell-actions" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
<a href="<?= e(url('contacts', ['group' => $g['id']])) ?>" role="button" class="secondary outline btn-sm">Pokaż członków</a>
<a href="<?= e(url('compose', ['group' => $g['id']])) ?>" role="button" class="secondary outline btn-sm"><?= icon('plain') ?>Wyślij SMS</a>
<?= Ui::iconBtnLink(url('groups', ['edit' => $g['id']]), 'pen', 'Zmień nazwę') ?>
<button type="submit" name="delete" value="1" class="icon-btn danger" aria-label="Usuń grupę" title="Usuń grupę"<?= Ui::confirm('Usunąć grupę „' . $g['name'] . '”?', 'Kontakty (' . (int) $g['members'] . ') zostaną w książce.', 'Usuń grupę') ?>><?= icon('trash-bin-trash') ?></button>
</form></td></tr>
<?php endif ?>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
