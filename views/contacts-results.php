<?php /** @var array $rows @var int $total @var int $page @var string $q @var string $group @var array $groups @var array $memberOf */ ?>
<?php if ($rows === []): ?>
<?= $q !== '' || $group !== '' ? Ui::empty('magnifer', 'Brak wyników' . ($q !== '' ? ' dla „' . $q . '”' : ''), 'Zmień frazę albo grupę.')
    : Ui::empty('users-group-two-rounded', 'Książka telefoniczna jest pusta', 'Dodaj pierwszy kontakt albo zaimportuj plik CSV.', '<a href="' . e(url('contact')) . '" role="button">' . icon('user-plus') . 'Dodaj kontakt</a>') ?>
<?php else: ?>
<form method="post" action="<?= e(url('contacts')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong>Zaznaczono <span data-bulk-count>0</span></strong>
<button type="submit" name="action" value="sms" class="secondary outline btn-sm"><?= icon('plain') ?>Wyślij SMS do zaznaczonych</button>
<label for="bulk-group" class="visually-hidden">Grupa</label>
<select id="bulk-group" name="group_id" class="btn-sm"><option value="">grupa…</option><?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach ?></select>
<button type="submit" name="action" value="add_group" class="secondary outline btn-sm">Dodaj do grupy</button>
<button type="submit" name="action" value="remove_group" class="secondary outline btn-sm">Usuń z grupy</button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm('Usunąć zaznaczone kontakty?', 'Wiadomości i rozmowy zostaną – zamiast nazw będą widoczne numery.', 'Usuń') ?>><?= icon('trash-bin-trash') ?>Usuń</button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="Zaznacz wszystkie"></th><th scope="col">Nazwa</th><th scope="col">Numer</th><th scope="col">Grupy</th><th scope="col">Notatka</th><th scope="col">Ostatni SMS</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($rows as $c): ?>
<tr><td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Zaznacz wiersz"></td>
<td><a href="<?= e(url('contact', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
<td><span class="mono"><?= e(Phone::format($c['phone'])) ?></span></td>
<td><span class="row"><?php foreach ($memberOf[(int) $c['id']] ?? [] as $gname): ?><span class="badge"><?= e($gname) ?></span><?php endforeach ?></span></td>
<td><span class="muted"><?= e(Ui::snippet((string) $c['note'], 40)) ?></span></td>
<td><?= e($c['last_sms'] ? fmt_short($c['last_sms']) : '—') ?></td>
<td><div class="cell-actions"><?= Ui::iconBtnLink(url('compose', ['contact' => $c['id']]), 'plain', 'Wyślij SMS') ?><?= Ui::iconBtnLink(url('contact', ['id' => $c['id']]), 'pen', 'Edytuj') ?></div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'contacts', ['q' => $q, 'group' => $group]) ?>
<?php endif ?>
