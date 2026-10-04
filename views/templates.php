<?php /** @var array $all @var int $id @var array $form @var ?string $error */ ?>
<header class="page-head"><div><h1><?= e(t('nav.templates')) ?></h1><p class="sub"><?= e(tn('templates.count', count($all))) ?> · <?= e(t('templates.sub_hint')) ?></p></div>
<div class="actions"><a href="<?= e(url('templates')) ?>" role="button"><?= icon('add-circle') ?><?= e(t('templates.new')) ?></a></div></header>
<?= flashes_html() ?>
<div class="split split-520">
<div class="tpl-list">
<?php if ($all === []): ?><section class="card"><?= Ui::empty('notes', t('templates.empty'), t('templates.empty_text')) ?></section><?php endif ?>
<?php foreach ($all as $t): ?>
<article class="tpl"<?= $id === (int) $t['id'] ? ' aria-current="true"' : '' ?>>
<header><strong><?= e($t['name']) ?></strong>
<form class="cell-actions" method="post" action="<?= e(url('templates', ['id' => $t['id']])) ?>"><?= csrf_field() ?>
<?= Ui::iconBtnLink(url('templates', ['id' => $t['id']]), 'pen', t('common.edit')) ?>
<button type="submit" name="delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"<?= Ui::confirm(t('templates.delete_q', ['name' => $t['name']]), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button></form></header>
<p><?= nl2br(e($t['body'])) ?></p>
<footer><span class="sms-counter"><?= e(SmsText::counter($t['body'])) ?></span><a href="<?= e(url('compose', ['template' => $t['id']])) ?>"><strong><?= e(t('templates.use')) ?></strong></a></footer>
</article>
<?php endforeach ?>
</div>
<section class="card">
<header><h2><?= e(t($id ? 'templates.edit' : 'templates.new')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e(url('templates', ['id' => $id ?: null])) ?>"><?= csrf_field() ?>
<?php if ($error): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="field"><label for="tname"><?= e(t('templates.name')) ?></label><input id="tname" name="name" type="text" value="<?= e($form['name']) ?>" required></div>
<div class="field"><label for="tpl-text"><?= e(t('common.body')) ?></label>
<textarea id="tpl-text" name="text" rows="5" data-sms data-sms-counter="tpl-counter" data-sms-why="tpl-why" required><?= e($form['body']) ?></textarea></div>
<span class="sms-counter" id="tpl-counter"></span>
<p class="sms-why" id="tpl-why" hidden></p>
<div class="row"><span class="muted"><?= e(t('templates.insert')) ?></span>
<button type="button" class="secondary outline btn-sm" data-insert="{imie}" data-target="tpl-text">{imie}</button>
<button type="button" class="secondary outline btn-sm" data-insert="{nazwa}" data-target="tpl-text">{nazwa}</button></div>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('templates.save')) ?></button><a href="<?= e(url('templates')) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
</section>
</div>
