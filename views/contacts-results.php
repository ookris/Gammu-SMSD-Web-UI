<?php /** @var array $rows @var int $total @var int $page @var string $q @var string $group @var array $groups @var array $memberOf */ ?>
<?php if ($rows === []): ?>
<?= $q !== '' || $group !== '' ? Ui::empty('magnifer', $q !== '' ? t('contacts.no_results_for', ['q' => $q]) : t('common.no_results'), t('contacts.change_filters'))
    : Ui::empty('users-group-two-rounded', t('contacts.empty'), t('contacts.empty_text'), '<a href="' . e(url('contact')) . '" role="button">' . icon('user-plus') . e(t('contacts.add')) . '</a>') ?>
<?php else: ?>
<form method="post" action="<?= e(url('contacts')) ?>">
<?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong><?= t('common.selected', ['n' => '<span data-bulk-count>0</span>']) ?></strong>
<button type="submit" name="action" value="sms" class="secondary outline btn-sm"><?= icon('plain') ?><?= e(t('contacts.sms_selected')) ?></button>
<label for="bulk-group" class="visually-hidden"><?= e(t('contacts.group')) ?></label>
<select id="bulk-group" name="group_id" class="btn-sm"><option value=""><?= e(t('contacts.group_ph')) ?></option><?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach ?></select>
<button type="submit" name="action" value="add_group" class="secondary outline btn-sm"><?= e(t('contacts.add_to_group')) ?></button>
<button type="submit" name="action" value="remove_group" class="secondary outline btn-sm"><?= e(t('contacts.remove_from_group')) ?></button>
<button type="submit" name="action" value="delete" class="btn-danger-outline btn-sm"<?= Ui::confirm(t('contacts.delete_selected'), t('contacts.delete_selected_text'), t('common.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('common.delete')) ?></button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="<?= e(t('common.select_all')) ?>"></th><th scope="col"><?= e(t('contacts.col_name')) ?></th><th scope="col"><?= e(t('contacts.col_phone')) ?></th><th scope="col"><?= e(t('contacts.col_groups')) ?></th><th scope="col"><?= e(t('contacts.col_note')) ?></th><th scope="col"><?= e(t('contacts.col_last')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $c): ?>
<tr><td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="<?= e(t('common.select_row')) ?>"></td>
<td><a href="<?= e(url('contact', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
<td><span class="mono"><?= e(Phone::format($c['phone'])) ?></span></td>
<td><span class="row"><?php foreach ($memberOf[(int) $c['id']] ?? [] as $gname): ?><span class="badge"><?= e($gname) ?></span><?php endforeach ?></span></td>
<td><span class="muted"><?= e(Ui::snippet((string) $c['note'], 40)) ?></span></td>
<td><?= e($c['last_sms'] ? fmt_short($c['last_sms']) : '—') ?></td>
<td><div class="cell-actions"><?= Ui::iconBtnLink(url('compose', ['contact' => $c['id']]), 'plain', t('calls.send_sms')) ?><?= Ui::iconBtnLink(url('contact', ['id' => $c['id']]), 'pen', t('common.edit')) ?></div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'contacts', ['q' => $q, 'group' => $group]) ?>
<?php endif ?>
