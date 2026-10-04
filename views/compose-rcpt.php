<?php /** @var array $plan @var bool $oob */ $r = $plan['recipients']; ?>
<div id="compose-rcpt"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<p class="row gap-lg"><?= icon('users-group-two-rounded') ?><strong><?= e(t('compose.recipients_n', ['n' => $plan['count']])) ?></strong>
<?php $notes = [];
if ($r['duplicates'] > 0) { $notes[] = tn('compose.duplicates', $r['duplicates']); }
if ($r['invalid'] !== []) { $notes[] = tn('compose.invalid_n', count($r['invalid'])); } ?>
<?php if ($notes): ?><span class="muted">(<?= e(implode(' · ', $notes)) ?>)</span><?php endif ?></p>
<?php if ($r['invalid'] !== []): ?>
<div class="alert alert-err tight" role="alert"><?= icon('danger-circle') ?><div><strong><?= e(tn('compose.fix_invalid', count($r['invalid']))) ?></strong>
<ul><?php foreach ($r['invalid'] as [$raw, $why]): ?><li><code><?= e($raw) ?></code> – <?= e($why) ?> <button type="button" class="link-btn" data-remove-number="<?= e($raw) ?>" data-target="numbers"><?= e(t('compose.remove')) ?></button></li><?php endforeach ?></ul></div></div>
<?php endif ?>
<?php foreach ($plan['warnings'] as $w): ?><?= alert('warn', '', $w) ?><?php endforeach ?>
</div>
