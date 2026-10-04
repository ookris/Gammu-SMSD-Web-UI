<?php /** @var ?array $contact @var array $form @var array $errors @var array $groups @var array $selected @var array $feed @var bool $blocked */
$cc = cfg('default_country_code', '48');
?>
<header class="page-head"><div><h1><?= e($contact['name'] ?? t('contact.new')) ?></h1>
<p class="sub"><?= e(t('contact.sub')) ?><?= $contact ? ' · ' . e(t('contact.added', ['date' => fmt_date((int) ts($contact['created_at']))])) : '' ?> · <a href="<?= e(url('contacts')) ?>"><?= e(t('common.back_to_list')) ?></a></p></div>
<?php if ($contact): ?>
<form class="actions" method="post" action="<?= e(url('contact', ['id' => $contact['id']])) ?>"><?= csrf_field() ?>
<a href="<?= e(url('compose', ['contact' => $contact['id']])) ?>" role="button"><?= icon('plain') ?><?= e(t('calls.send_sms')) ?></a>
<?php if ($blocked): ?><span class="badge badge-err"><?= e(t('inbox.blocked')) ?></span><?php else: ?>
<button type="submit" name="block" value="1" class="secondary"<?= Ui::confirm(t('contact.block_q', ['name' => $contact['name']]), t('calls.block_text'), t('inbox.block')) ?>><?= icon('forbidden-circle') ?><?= e(t('inbox.block')) ?></button><?php endif ?>
<button type="submit" name="delete" value="1" class="btn-danger-outline"<?= Ui::confirm(t('contact.delete_q', ['name' => $contact['name']]), t('contact.delete_text', ['phone' => Phone::format($contact['phone'])]), t('contact.delete')) ?>><?= icon('trash-bin-trash') ?><?= e(t('common.delete')) ?></button>
</form>
<?php endif ?>
</header>
<?= flashes_html() ?>
<div class="split split-440">
<section class="card">
<header><h2><?= e(t('contact.data')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e(url('contact', ['id' => $contact['id'] ?? null])) ?>"><?= csrf_field() ?>
<div class="grid-2">
<div class="field"><label for="name"><?= e(t('contacts.col_name')) ?></label><input id="name" name="name" type="text" value="<?= e($form['name']) ?>" required<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($errors['name'])): ?><small class="warn"><?= e($errors['name']) ?></small><?php endif ?></div>
<div class="field"><label for="phone"><?= e(t('contact.phone')) ?></label>
<input id="phone" aria-describedby="phone-h" name="phone" type="text" value="<?= e($form['phone_input']) ?>" class="mono-input" required inputmode="tel"<?= isset($errors['phone']) ? ' aria-invalid="true"' : '' ?>>
<small id="phone-h"<?= isset($errors['phone']) ? ' class="warn"' : '' ?>><?= e($errors['phone'] ?? ($contact ? t('contact.saved_as', ['phone' => Phone::toGammu($contact['phone'])]) . ' · ' : '') . t('contact.prefix_hint', ['n' => (int) cfg('national_number_length', 9), 'cc' => $cc])) ?></small></div>
</div>
<div class="field"><label for="note"><?= e(t('contacts.col_note')) ?></label><textarea id="note" name="note" rows="3"><?= e((string) $form['note']) ?></textarea></div>
<fieldset><legend><?= e(t('contact.groups')) ?></legend>
<div class="grid-2">
<?php foreach ($groups as $g): ?><label><input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $selected, true) ? ' checked' : '' ?>> <span><?= e($g['name']) ?></span></label><?php endforeach ?>
<?php if ($groups === []): ?><p class="muted small"><?= t('contact.no_groups', ['link' => '<a href="' . e(url('groups')) . '">' . e(t('contact.create_group')) . '</a>']) ?></p><?php endif ?>
</div></fieldset>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('common.save')) ?></button><a href="<?= e(url('contacts')) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
</section>
<?php if ($contact): ?>
<section class="card">
<header><h2><?= e(t('contact.feed')) ?></h2><a href="<?= e(url('threads', ['phone' => $contact['phone']])) ?>"><strong><?= e(t('contact.open_thread')) ?></strong></a></header>
<?php if ($feed === []): ?><p class="muted"><?= e(t('contact.feed_empty')) ?></p><?php endif ?>
<ul class="feed">
<?php foreach ($feed as $it): ?>
<?php if ($it['kind'] === 'call'): ?>
<li class="call"><?= icon('end-call') ?><div><div><?= e(t('contact.call_rejected')) ?></div><div class="meta"><?= e(fmt_when($it['at'])) ?></div></div></li>
<?php else: $out = $it['direction'] === 'out'; ?>
<li class="<?= $out ? 'out' : 'in' ?>"><?= icon($out ? 'plain' : 'inbox-in') ?><div><div><?= e(Ui::snippet($it['body'], 140)) ?></div>
<div class="meta"><?= e(fmt_when($it['at'])) ?><?= $out ? ' ·' . Ui::status($it, false) : '' ?></div></div></li>
<?php endif ?>
<?php endforeach ?>
</ul>
</section>
<?php endif ?>
</div>
