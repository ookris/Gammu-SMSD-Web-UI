<?php /** @var array $rules @var int $ruleId @var array $ruleForm @var ?string $ruleError @var array $channels @var array $groups */
$tabUrl = static fn (array $extra = []) => url('automations', ['tab' => 'rules'] + $extra);
?>
<div class="split split-520">
<div class="tpl-list">
<?php if ($channels === []): ?><section class="card"><?= Ui::empty('letter', t('rules.no_channels'), t('rules.no_channels_text')) ?></section>
<?php elseif ($rules === []): ?><section class="card"><?= Ui::empty('forward', t('rules.empty'), t('rules.empty_text')) ?></section><?php endif ?>
<?php foreach ($rules as $r): ?>
<article class="tpl"<?= $ruleId === (int) $r['id'] ? ' aria-current="true"' : '' ?>>
<header><strong><?= e($r['name']) ?></strong><?= $r['enabled'] ? '' : ' <span class="badge badge-warn">' . e(t('channels.disabled')) . '</span>' ?>
<form class="cell-actions" method="post" action="<?= e($tabUrl(['rule' => $r['id']])) ?>"><?= csrf_field() ?>
<?= Ui::iconBtnLink($tabUrl(['rule' => $r['id']]), 'pen', t('common.edit')) ?>
<button type="submit" name="rule_test" value="1" class="icon-btn" aria-label="<?= e(t('channels.test')) ?>" title="<?= e(t('channels.test')) ?>"><?= icon('play') ?></button>
<button type="submit" name="rule_toggle" value="<?= $r['enabled'] ? '0' : '1' ?>" class="icon-btn" aria-label="<?= e(t($r['enabled'] ? 'channels.disable' : 'channels.enable')) ?>" title="<?= e(t($r['enabled'] ? 'channels.disable' : 'channels.enable')) ?>"><?= icon($r['enabled'] ? 'forbidden-circle' : 'check-circle') ?></button>
<button type="submit" name="rule_delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"<?= Ui::confirm(t('rules.delete_q', ['name' => $r['name']]), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button></form></header>
<p><?= e(Rules::describe($r)) ?> → <strong><?= e($r['channel_name'] ?? '?') ?></strong></p>
<footer><span class="muted small"><?= e(tn('rules.hits', (int) $r['hits'])) ?><?= $r['last_hit_at'] ? ' · ' . e(t('rules.last_hit', ['time' => fmt_when($r['last_hit_at'])])) : '' ?><?= $r['last_error'] ? ' · ' . e(t('channels.last_error', ['error' => $r['last_error']])) : '' ?></span></footer>
</article>
<?php endforeach ?>
</div>
<section class="card">
<header><h2><?= e(t($ruleId ? 'rules.edit' : 'rules.new')) ?></h2></header>
<?php if ($channels === []): ?><p class="muted"><?= e(t('rules.no_channels_text')) ?></p><?php else: ?>
<form class="stack-sm" method="post" action="<?= e($tabUrl($ruleId ? ['rule' => $ruleId] : [])) ?>"><?= csrf_field() ?>
<?php if ($ruleError): ?><?= alert('err', '', $ruleError) ?><?php endif ?>
<div class="field"><label for="r-name"><?= e(t('rules.name')) ?></label><input id="r-name" name="name" type="text" value="<?= e($ruleForm['name']) ?>" placeholder="<?= e(t('rules.name_ph')) ?>"></div>
<div class="grid-2">
<div class="field"><label for="r-event"><?= e(t('rules.event')) ?></label><select id="r-event" name="event">
<?php foreach (['sms' => 'rules.ev_sms', 'call' => 'rules.ev_call'] as $k => $key): ?><option value="<?= $k ?>"<?= $ruleForm['event'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="r-cond"><?= e(t('rules.cond')) ?></label><select id="r-cond" name="cond_type" data-type-switch>
<?php foreach (['all' => 'rules.c_all', 'number' => 'rules.c_number', 'group' => 'rules.c_group', 'text' => 'rules.c_text'] as $k => $key): ?><option value="<?= $k ?>"<?= $ruleForm['cond_type'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
</div>
<div class="field" data-show-by="cond_type" data-show-for="number"<?= $ruleForm['cond_type'] === 'number' ? '' : ' hidden' ?>><label for="r-number"><?= e(t('rules.number')) ?></label><input id="r-number" name="number" type="text" value="<?= e($ruleForm['number']) ?>"><small><?= e(t('rules.number_hint')) ?></small></div>
<div class="field" data-show-by="cond_type" data-show-for="group"<?= $ruleForm['cond_type'] === 'group' ? '' : ' hidden' ?>><label for="r-group"><?= e(t('rules.group')) ?></label><select id="r-group" name="group">
<?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $ruleForm['group'] === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach ?></select></div>
<div class="field" data-show-by="cond_type" data-show-for="text"<?= $ruleForm['cond_type'] === 'text' ? '' : ' hidden' ?>><label for="r-text"><?= e(t('rules.text')) ?></label><input id="r-text" name="cond_text" type="text" value="<?= e($ruleForm['text']) ?>"><small><?= e(t('rules.text_hint')) ?></small></div>
<div class="field"><label for="r-channel"><?= e(t('rules.channel')) ?></label><select id="r-channel" name="channel_id">
<?php foreach ($channels as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $ruleForm['channel_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= e(t('channels.type_' . $c['type'])) ?>)</option><?php endforeach ?></select></div>
<p class="muted small"><?= e(t('rules.blocked_note')) ?></p>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button><a href="<?= e($tabUrl()) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
<?php endif ?>
</section>
</div>
