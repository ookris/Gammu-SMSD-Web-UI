<?php /** @var array $rules @var int $ruleId @var array $ruleForm @var ?string $ruleError @var array $groups */
$tabUrl = static fn (array $extra = []) => url('automations', ['tab' => 'replies'] + $extra);
$days = static fn (array $r) => $r['workdays_only'] ? ' ' . t('replies.workdays_short') : '';
?>
<div class="split split-520">
<div class="tpl-list">
<?php if ($rules === []): ?><section class="card"><?= Ui::empty('chat-round-line', t('replies.empty'), t('replies.empty_text')) ?></section><?php endif ?>
<?php foreach ($rules as $r): ?>
<article class="tpl"<?= $ruleId === (int) $r['id'] ? ' aria-current="true"' : '' ?>>
<header><strong><?= e($r['name']) ?></strong><?= $r['enabled'] ? '' : ' <span class="badge badge-warn">' . e(t('channels.disabled')) . '</span>' ?>
<form class="cell-actions" method="post" action="<?= e($tabUrl(['rule' => $r['id']])) ?>"><?= csrf_field() ?>
<?= Ui::iconBtnLink($tabUrl(['rule' => $r['id']]), 'pen', t('common.edit')) ?>
<button type="submit" name="rule_toggle" value="<?= $r['enabled'] ? '0' : '1' ?>" class="icon-btn" aria-label="<?= e(t($r['enabled'] ? 'channels.disable' : 'channels.enable')) ?>" title="<?= e(t($r['enabled'] ? 'channels.disable' : 'channels.enable')) ?>"><?= icon($r['enabled'] ? 'forbidden-circle' : 'check-circle') ?></button>
<button type="submit" name="rule_delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"<?= Ui::confirm(t('rules.delete_q', ['name' => $r['name']]), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button></form></header>
<p><?= e(Rules::describe($r)) ?><?php if ($r['hours_mode'] !== 'any'): ?> · <?= e(t('replies.h_' . $r['hours_mode'], ['from' => substr((string) $r['hours_from'], 0, 5), 'to' => substr((string) $r['hours_to'], 0, 5)])) ?><?= e($days($r)) ?><?php endif ?></p>
<p class="muted small">→ <?= e(Ui::snippet((string) $r['reply_text'], 120)) ?></p>
<footer><span class="muted small"><?= e(tn('rules.hits', (int) $r['hits'])) ?><?= $r['last_hit_at'] ? ' · ' . e(t('rules.last_hit', ['time' => fmt_when($r['last_hit_at'])])) : '' ?><?= $r['last_error'] ? ' · ' . e(t('channels.last_error', ['error' => $r['last_error']])) : '' ?></span></footer>
</article>
<?php endforeach ?>
</div>
<section class="card">
<header><h2><?= e(t($ruleId ? 'replies.edit' : 'replies.new')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e($tabUrl($ruleId ? ['rule' => $ruleId] : [])) ?>"><?= csrf_field() ?>
<?php if ($ruleError): ?><?= alert('err', '', $ruleError) ?><?php endif ?>
<div class="field"><label for="r-name"><?= e(t('rules.name')) ?></label><input id="r-name" name="name" type="text" value="<?= e($ruleForm['name']) ?>" placeholder="<?= e(t('replies.name_ph')) ?>"></div>
<div class="grid-2">
<div class="field"><label for="r-event"><?= e(t('rules.event')) ?></label><select id="r-event" name="event">
<?php foreach (['sms' => 'rules.ev_sms', 'call' => 'rules.ev_call'] as $k => $key): ?><option value="<?= $k ?>"<?= $ruleForm['event'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="r-cond"><?= e(t('rules.cond')) ?></label><select id="r-cond" name="cond_type" data-type-switch>
<?php foreach (['all' => 'rules.c_all', 'equals' => 'replies.c_equals', 'starts' => 'replies.c_starts', 'text' => 'rules.c_text', 'number' => 'rules.c_number', 'group' => 'rules.c_group'] as $k => $key): ?><option value="<?= $k ?>"<?= $ruleForm['cond_type'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
</div>
<div class="field" data-show-by="cond_type" data-show-for="equals starts text"<?= in_array($ruleForm['cond_type'], ['equals', 'starts', 'text'], true) ? '' : ' hidden' ?>><label for="r-text"><?= e(t('replies.keyword')) ?></label><input id="r-text" name="cond_text" type="text" value="<?= e($ruleForm['text']) ?>" placeholder="STOP"><small><?= e(t('rules.text_hint')) ?></small></div>
<div class="field" data-show-by="cond_type" data-show-for="number"<?= $ruleForm['cond_type'] === 'number' ? '' : ' hidden' ?>><label for="r-number"><?= e(t('rules.number')) ?></label><input id="r-number" name="number" type="text" value="<?= e($ruleForm['number']) ?>"><small><?= e(t('rules.number_hint')) ?></small></div>
<div class="field" data-show-by="cond_type" data-show-for="group"<?= $ruleForm['cond_type'] === 'group' ? '' : ' hidden' ?>><label for="r-group"><?= e(t('rules.group')) ?></label><select id="r-group" name="group">
<?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $ruleForm['group'] === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach ?></select></div>
<fieldset><legend><?= e(t('replies.hours')) ?></legend>
<div class="field"><label for="r-hm" class="visually-hidden"><?= e(t('replies.hours')) ?></label><select id="r-hm" name="hours_mode" data-type-switch>
<?php foreach (['any' => 'replies.hm_any', 'outside' => 'replies.hm_outside', 'inside' => 'replies.hm_inside'] as $k => $key): ?><option value="<?= $k ?>"<?= $ruleForm['hours_mode'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<div class="stack-sm" data-show-by="hours_mode" data-show-for="outside inside"<?= in_array($ruleForm['hours_mode'], ['outside', 'inside'], true) ? '' : ' hidden' ?>>
<div class="inline-pair"><label for="r-hf" class="visually-hidden"><?= e(t('settings.from')) ?></label><input id="r-hf" name="hours_from" type="time" value="<?= e($ruleForm['hours_from']) ?>"><span>–</span>
<label for="r-ht" class="visually-hidden"><?= e(t('settings.to')) ?></label><input id="r-ht" name="hours_to" type="time" value="<?= e($ruleForm['hours_to']) ?>"></div>
<label><input type="checkbox" name="workdays" value="1"<?= $ruleForm['workdays'] ? ' checked' : '' ?>> <span><?= e(t('replies.workdays')) ?></span></label></div>
</fieldset>
<div class="field"><label for="r-reply"><?= e(t('replies.text')) ?></label>
<textarea id="r-reply" name="reply_text" rows="4" data-sms data-sms-counter="r-counter" data-sms-why="r-why" required><?= e($ruleForm['reply_text']) ?></textarea></div>
<div class="row"><span class="sms-counter" id="r-counter" aria-live="polite"><?= e(SmsText::counter($ruleForm['reply_text'])) ?></span><span class="row"><span class="muted"><?= e(t('templates.insert')) ?></span>
<button type="button" class="secondary outline btn-sm" data-insert="{imie}" data-target="r-reply">{imie}</button>
<button type="button" class="secondary outline btn-sm" data-insert="{nazwa}" data-target="r-reply">{nazwa}</button></span></div>
<p class="sms-why" id="r-why" hidden></p>
<p class="muted small"><?= e(t('replies.safety')) ?></p>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button><a href="<?= e($tabUrl()) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
</section>
</div>
