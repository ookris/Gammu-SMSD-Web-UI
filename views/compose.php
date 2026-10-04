<?php
/** @var array $in @var array $plan @var array $groups @var array $contacts @var array $templates @var bool $attempted @var bool $confirm */
$r = $plan['recipients'];
$checked = static fn (bool $c) => $c ? ' checked' : '';
?>
<header class="page-head"><div><h1><?= e(t('nav.compose')) ?></h1><p class="sub"><?= e(t('compose.sub')) ?></p></div></header>
<?= flashes_html() ?>
<?php if ($attempted): ?>
<div class="alert alert-err" role="alert"><?= icon('danger-circle') ?><div><strong><?= e(t('compose.nothing_sent')) ?></strong>
<ul><?php foreach ($plan['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach ?></ul></div></div>
<?php endif ?>
<form method="post" action="<?= e(url('compose')) ?>" class="split" id="compose-form"
      hx-post="<?= e(url('compose', ['preview' => 1])) ?>" hx-trigger="input delay:600ms, change" hx-target="#compose-side" hx-swap="outerHTML">
<?= csrf_field() ?>
<input type="hidden" name="send_token" value="<?= e($sendToken) ?>">
<div class="stack">
<section class="card">
<header><h2><?= e(t('compose.step_recipients')) ?></h2></header>
<div class="grid-2">
<fieldset><legend><?= e(t('compose.groups')) ?></legend>
<div class="pick-list">
<?php foreach ($groups as $g): ?>
<label><input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"<?= $checked(in_array((int) $g['id'], $in['groups'], true)) ?>> <span><?= e($g['name']) ?></span><span class="muted"><?= e(t('compose.members', ['n' => (int) $g['members']])) ?></span></label>
<?php endforeach ?>
<?php if ($groups === []): ?><p class="muted small"><?= t('compose.no_groups', ['link' => '<a href="' . e(url('groups')) . '">' . e(t('nav.groups')) . '</a>']) ?></p><?php endif ?>
</div></fieldset>
<fieldset><legend><?= e(t('compose.contacts')) ?></legend>
<input id="csearch" name="contacts_q" type="search" placeholder="<?= e(t('compose.search_contact')) ?>" aria-label="<?= e(t('compose.search_contact')) ?>" autocomplete="off"
       hx-get="<?= e(url('compose')) ?>" hx-trigger="input changed delay:300ms, search" hx-target="#contact-pick" hx-include="#contact-pick" hx-sync="this:replace">
<div class="pick-list gap-lg" id="contact-pick">
<?= view('compose-contacts', ['contacts' => $contacts, 'selected' => $in['contacts']]) ?>
</div></fieldset>
</div>
<div class="gap-lg"><div class="field">
<label for="numbers"><?= e(t('compose.numbers')) ?></label>
<textarea id="numbers" aria-describedby="numbers-h" name="numbers" rows="3" class="mono-input"><?= e($in['numbers']) ?></textarea>
<small id="numbers-h"><?= e(t('compose.numbers_hint', ['n' => (int) cfg('national_number_length', 9), 'cc' => cfg('default_country_code', '48')])) ?></small>
</div></div>
<?= view('compose-rcpt', ['plan' => $plan, 'oob' => false]) ?>
</section>
<section class="card">
<header><h2><?= e(t('compose.step_text')) ?></h2></header>
<div class="stack-sm">
<?php if ($templates !== []): ?>
<div class="field"><label for="template"><?= e(t('compose.template')) ?></label>
<select id="template" name="template" data-template-target="msg">
<option value=""><?= e(t('compose.no_template')) ?></option>
<?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" data-body="<?= e($t['body']) ?>"><?= e($t['name']) ?></option><?php endforeach ?>
</select></div>
<?php endif ?>
<div class="field"><label for="msg"><?= e(t('common.body')) ?></label>
<textarea id="msg" name="text" rows="5" data-sms data-sms-counter="msg-counter" data-sms-why="msg-why" aria-describedby="msg-counter msg-why"><?= e($in['text']) ?></textarea></div>
<div class="row"><span class="sms-counter" id="msg-counter" aria-live="polite"><?= e(SmsText::counter($in['translit'] ? SmsText::translit($in['text']) : $in['text'])) ?></span><span class="row"><span class="muted"><?= e(t('templates.insert')) ?></span>
<button type="button" class="secondary outline btn-sm" data-insert="{imie}" data-target="msg">{imie}</button>
<button type="button" class="secondary outline btn-sm" data-insert="{nazwa}" data-target="msg">{nazwa}</button></span></div>
<p class="sms-why" id="msg-why" hidden></p>
<label><input type="checkbox" name="translit" value="1" data-sms-translit="msg"<?= $checked($in['translit']) ?>> <span><?= e(t('compose.translit')) ?></span></label>
<?= view('compose-vars', ['plan' => $plan, 'oob' => false]) ?>
</div>
</section>
<section class="card">
<header><h2><?= e(t('compose.step_options')) ?></h2></header>
<div class="stack-sm">
<div class="grid-2">
<div class="stack-sm">
<label><input type="checkbox" name="report" value="1"<?= $checked($in['report']) ?>> <span><?= e(t('compose.report')) ?></span></label>
<label><input type="checkbox" name="flash" value="1"<?= $checked($in['flash']) ?>> <span><?= e(t('compose.flash')) ?></span></label>
<label><input type="checkbox" name="priority" value="1"<?= $checked($in['priority']) ?>> <span><?= e(t('compose.priority')) ?><span class="check-sub"><?= e(t('compose.priority_hint')) ?></span></span></label>
</div>
<div class="field"><label for="send_at"><?= e(t('compose.send_at')) ?></label>
<input id="send_at" name="send_at" type="datetime-local" value="<?= e($in['send_at']) ?>" min="<?= e(date('Y-m-d\TH:i')) ?>"></div>
</div>
<?= view('compose-window', ['plan' => $plan, 'in' => $in, 'oob' => false]) ?>
<label><input type="checkbox" name="skip_window" value="1"<?= $checked($in['skip_window']) ?>> <span><?= e(t('compose.skip_window')) ?></span></label>
</div>
</section>
</div>
<?= view('compose-side', ['in' => $in, 'plan' => $plan, 'oob' => false, 'confirm' => $confirm]) ?>
</form>
