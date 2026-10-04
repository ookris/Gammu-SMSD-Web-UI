<?php
/** @var array $in @var array $plan @var bool $oob */
$confirm ??= false;
$a = $plan['analysis'];
$coding = $a['gsm'] ? 'GSM-7' : 'Unicode';
$pace = $plan['per_min'] > 0 ? t('compose.pace_value', ['n' => $plan['per_min'], 'time' => fmt_duration(max(60, $plan['duration']))]) : t('batch.no_throttle');
?>
<div class="stack" id="compose-side">
<section class="card">
<header><h2><?= e(t('compose.summary')) ?></h2></header>
<dl class="kv">
<dt><?= e(t('batch.recipients')) ?></dt><dd><?= $plan['count'] ?></dd>
<dt><?= e(t('common.parts')) ?></dt><dd><?= e(t('compose.parts_per', ['n' => $plan['parts'], 'coding' => $coding])) ?></dd>
<dt><?= e(t('compose.total')) ?></dt><dd><strong><?= $plan['count'] ?> × <?= $plan['parts'] ?> = <?= $plan['total'] ?> SMS</strong></dd>
<?php if ($plan['multi']): ?><dt><?= e(t('batch.pace')) ?></dt><dd><?= e($pace) ?></dd><?php endif ?>
<?php if ($plan['delayed']): ?><dt><?= e(t('compose.start')) ?></dt><dd><?= e(Compose::startLabel($plan)) ?></dd><?php endif ?>
</dl>
<div class="stack-sm gap-lg">
<?php if ($plan['multi']): ?>
<button type="button" data-dialog-open="confirm-send"><?= icon('plain') ?><?= e(tn('compose.send_to', $plan['count'])) ?></button>
<?php else: ?>
<button type="submit" name="send" value="1"><?= icon('plain') ?><?= e(t('compose.send')) ?></button>
<?php endif ?>
<button type="submit" name="save_template" value="1" class="secondary outline" formnovalidate><?= icon('diskette') ?><?= e(t('compose.save_template')) ?></button>
</div>
</section>
<?php if ($plan['preview'] !== null): ?>
<section class="card"><header><h2><?= e(t('compose.preview')) ?></h2></header>
<p class="muted small"><?= e(t('compose.preview_who', ['name' => $plan['preview']['name']])) ?></p>
<div class="preview-bubble"><?= nl2br(e($plan['preview']['text'])) ?></div></section>
<?php endif ?>
<?php if ($plan['multi']): ?>
<dialog id="confirm-send" aria-labelledby="confirm-send-t"<?= $confirm ? ' data-autoopen' : '' ?>>
<article class="dlg-sm">
<div class="dlg-head"><span class="dlg-icon accent"><?= icon('plain', 'icon-lg') ?></span>
<div><h2 id="confirm-send-t"><?= e(tn('compose.confirm_q', $plan['count'], ['total' => $plan['total']])) ?></h2>
<p><?= e(t('compose.confirm_text')) ?></p></div></div>
<div class="dlg-body"><dl class="kv kv-170">
<dt><?= e(t('batch.recipients')) ?></dt><dd><?= $plan['count'] ?><?= $plan['recipients']['duplicates'] ? ' (' . e(tn('compose.duplicates', $plan['recipients']['duplicates'])) . ')' : '' ?></dd>
<dt><?= e(t('compose.messages')) ?></dt><dd><?= $plan['total'] ?> SMS · <?= e(tn('compose.parts_n', $plan['parts'])) ?> · <?= e($coding) ?></dd>
<dt><?= e(t('batch.pace')) ?></dt><dd><?= e($pace) ?></dd>
<dt><?= e(t('compose.start')) ?></dt><dd><?= e(Compose::startLabel($plan)) ?><?= $plan['delayed'] && $plan['send_at'] === null ? ' ' . e(t('compose.window_note')) : '' ?></dd>
<dt><?= e(t('batch.report')) ?></dt><dd><?= e(t($in['report'] ? 'common.yes' : 'common.no')) ?></dd>
<?php $notes = [];
if ($plan['recipients']['blocked']) { $notes[] = t('compose.note_blocked', ['n' => count($plan['recipients']['blocked'])]); }
if ($plan['vars_missing']) { $notes[] = t('compose.note_vars', ['n' => $plan['vars_missing']]); }
if ($plan['errors']) { $notes[] = t('compose.note_errors'); } ?>
<?php if ($notes): ?><dt><?= e(t('compose.notes')) ?></dt><dd><?= e(implode(' · ', $notes)) ?></dd><?php endif ?>
</dl></div>
<footer><button type="button" class="secondary outline" data-dialog-close><?= e(t('common.cancel')) ?></button>
<button type="submit" form="compose-form" name="confirm" value="1"><?= icon('plain') ?><?= e(t('compose.send')) ?></button></footer>
</article>
</dialog>
<?php endif ?>
</div>
<?php if ($oob): ?>
<?= view('compose-rcpt', ['plan' => $plan, 'oob' => true]) ?>
<?= view('compose-vars', ['plan' => $plan, 'oob' => true]) ?>
<?= view('compose-window', ['plan' => $plan, 'in' => $in, 'oob' => true]) ?>
<?php endif ?>
