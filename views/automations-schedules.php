<?php /** @var array $schedules @var int $schedId @var array $schedForm @var ?string $schedError @var array $groups */
$tabUrl = static fn (array $extra = []) => url('automations', ['tab' => 'schedules'] + $extra);
$f = $schedForm;
?>
<div class="split split-520">
<div class="tpl-list">
<?php if ($schedules === []): ?><section class="card"><?= Ui::empty('calendar', t('schedules.empty'), t('schedules.empty_text')) ?></section><?php endif ?>
<?php foreach ($schedules as $s): ?>
<article class="tpl"<?= $schedId === (int) $s['id'] ? ' aria-current="true"' : '' ?>>
<header><strong><?= e($s['name']) ?></strong><?= $s['enabled'] ? '' : ' <span class="badge badge-warn">' . e(t('channels.disabled')) . '</span>' ?>
<form class="cell-actions" method="post" action="<?= e($tabUrl(['sched' => $s['id']])) ?>"><?= csrf_field() ?>
<?= Ui::iconBtnLink($tabUrl(['sched' => $s['id']]), 'pen', t('common.edit')) ?>
<button type="submit" name="sched_toggle" value="<?= $s['enabled'] ? '0' : '1' ?>" class="icon-btn" aria-label="<?= e(t($s['enabled'] ? 'channels.disable' : 'channels.enable')) ?>" title="<?= e(t($s['enabled'] ? 'channels.disable' : 'channels.enable')) ?>"><?= icon($s['enabled'] ? 'forbidden-circle' : 'check-circle') ?></button>
<button type="submit" name="sched_delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"<?= Ui::confirm(t('schedules.delete_q', ['name' => $s['name']]), '', t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button></form></header>
<p><?= e(Schedules::describe($s)) ?></p>
<p class="muted small"><?= e(Ui::snippet((string) $s['body'], 120)) ?></p>
<footer><span class="muted small"><?= $s['enabled'] && $s['next_run_at'] ? e(t('schedules.next', ['time' => fmt_when($s['next_run_at'])])) : '' ?><?= $s['last_run_at'] ? ' · ' . e(t('schedules.last', ['time' => fmt_when($s['last_run_at']), 'n' => $s['runs']])) : '' ?><?= $s['last_error'] ? ' · ' . e(t('channels.last_error', ['error' => $s['last_error']])) : '' ?></span></footer>
</article>
<?php endforeach ?>
</div>
<section class="card">
<header><h2><?= e(t($schedId ? 'schedules.edit' : 'schedules.new')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e($tabUrl($schedId ? ['sched' => $schedId] : [])) ?>"><?= csrf_field() ?>
<?php if ($schedError): ?><?= alert('err', '', $schedError) ?><?php endif ?>
<div class="field"><label for="s-name"><?= e(t('rules.name')) ?></label><input id="s-name" name="name" type="text" value="<?= e($f['name']) ?>" placeholder="<?= e(t('schedules.name_ph')) ?>"></div>
<div class="field"><label for="s-groups"><?= e(t('schedules.groups')) ?></label><select id="s-groups" name="groups[]" multiple size="<?= min(6, max(2, count($groups))) ?>">
<?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $f['groups'], true) ? ' selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['members'] ?>)</option><?php endforeach ?></select></div>
<div class="field"><label for="s-numbers"><?= e(t('compose.numbers')) ?></label><textarea id="s-numbers" name="numbers" rows="2"><?= e($f['numbers']) ?></textarea></div>
<div class="field"><label for="s-text"><?= e(t('common.body')) ?></label>
<textarea id="s-text" name="text" rows="4" data-sms data-sms-counter="s-counter" data-sms-why="s-why" required><?= e($f['text']) ?></textarea></div>
<div class="row"><span class="sms-counter" id="s-counter" aria-live="polite"><?= e(SmsText::counter($f['text'])) ?></span><span class="row"><span class="muted"><?= e(t('templates.insert')) ?></span>
<button type="button" class="secondary outline btn-sm" data-insert="{imie}" data-target="s-text">{imie}</button>
<button type="button" class="secondary outline btn-sm" data-insert="{nazwa}" data-target="s-text">{nazwa}</button></span></div>
<p class="sms-why" id="s-why" hidden></p>
<div class="stack-sm">
<label><input type="checkbox" name="translit" value="1" data-sms-translit="s-text"<?= $f['translit'] ? ' checked' : '' ?>> <span><?= e(t('compose.translit')) ?></span></label>
<label><input type="checkbox" name="report" value="1"<?= $f['report'] ? ' checked' : '' ?>> <span><?= e(t('compose.report')) ?></span></label>
<label><input type="checkbox" name="flash" value="1"<?= $f['flash'] ? ' checked' : '' ?>> <span><?= e(t('compose.flash')) ?></span></label></div>
<fieldset><legend><?= e(t('schedules.when')) ?></legend>
<div class="grid-2">
<div class="field"><label for="s-freq"><?= e(t('schedules.frequency')) ?></label><select id="s-freq" name="frequency" data-type-switch>
<?php foreach (['daily' => 'schedules.f_daily', 'weekly' => 'schedules.f_weekly', 'monthly' => 'schedules.f_monthly'] as $k => $key): ?><option value="<?= $k ?>"<?= $f['frequency'] === $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="s-time"><?= e(t('schedules.time')) ?></label><input id="s-time" name="time" type="time" value="<?= e($f['time']) ?>" required></div></div>
<div class="row" data-show-by="frequency" data-show-for="weekly"<?= $f['frequency'] === 'weekly' ? '' : ' hidden' ?>>
<?php foreach (lang_entry('schedules.days_short') as $i => $label): ?><label><input type="checkbox" name="weekdays[]" value="<?= $i + 1 ?>"<?= in_array($i + 1, $f['weekdays'], true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?></div>
<div class="field" data-show-by="frequency" data-show-for="monthly"<?= $f['frequency'] === 'monthly' ? '' : ' hidden' ?>><label for="s-day"><?= e(t('schedules.month_day')) ?></label><input id="s-day" name="month_day" type="number" min="1" max="31" value="<?= (int) $f['month_day'] ?>"><small><?= e(t('schedules.month_day_hint')) ?></small></div>
</fieldset>
<p class="muted small"><?= e(t('schedules.note')) ?></p>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button><a href="<?= e($tabUrl()) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
</section>
</div>
