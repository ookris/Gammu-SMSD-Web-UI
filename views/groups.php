<?php /** @var array $groups @var int $edit */ ?>
<header class="page-head"><div><h1><?= e(t('nav.groups')) ?></h1><p class="sub"><?= e(tn('groups.count', count($groups))) ?> · <?= e(t('groups.sub_hint')) ?></p></div></header>
<?= flashes_html() ?>
<section class="card">
<form class="row row-end" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?>
<div class="field grow"><label for="gname"><?= e(t('groups.new_name')) ?></label><input id="gname" name="name" type="text" placeholder="<?= e(t('groups.new_name_ph')) ?>" required></div>
<button type="submit"><?= icon('add-circle') ?><?= e(t('groups.add')) ?></button></form>
</section>
<div class="gap-lg"></div>
<section class="card flush">
<?php if ($groups === []): ?><?= Ui::empty('folder-with-files', t('groups.empty'), t('groups.empty_text')) ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('groups.col_name')) ?></th><th scope="col"><?= e(t('groups.col_members')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($groups as $g): ?>
<?php if ($edit === (int) $g['id']): ?>
<tr><td><form class="row" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
<label for="rename" class="visually-hidden"><?= e(t('groups.rename_label')) ?></label><input id="rename" name="name" value="<?= e($g['name']) ?>" required autofocus>
<button type="submit" class="btn-sm"><?= e(t('common.save')) ?></button><a href="<?= e(url('groups')) ?>" role="button" class="secondary outline btn-sm"><?= e(t('common.cancel')) ?></a></form></td>
<td><?= (int) $g['members'] ?></td><td><span class="muted"><?= e(t('groups.renaming')) ?></span></td></tr>
<?php else: ?>
<tr><td><strong><?= e($g['name']) ?></strong></td><td><?= (int) $g['members'] ?></td>
<td><form class="cell-actions" method="post" action="<?= e(url('groups')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
<a href="<?= e(url('contacts', ['group' => $g['id']])) ?>" role="button" class="secondary outline btn-sm"><?= e(t('groups.show_members')) ?></a>
<a href="<?= e(url('compose', ['group' => $g['id']])) ?>" role="button" class="secondary outline btn-sm"><?= icon('plain') ?><?= e(t('calls.send_sms')) ?></a>
<?= Ui::iconBtnLink(url('groups', ['edit' => $g['id']]), 'pen', t('groups.rename')) ?>
<button type="submit" name="delete" value="1" class="icon-btn danger" aria-label="<?= e(t('groups.delete')) ?>" title="<?= e(t('groups.delete')) ?>"<?= Ui::confirm(t('groups.delete_q', ['name' => $g['name']]), t('groups.delete_text', ['n' => (int) $g['members']]), t('groups.delete')) ?>><?= icon('trash-bin-trash') ?></button>
</form></td></tr>
<?php endif ?>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
