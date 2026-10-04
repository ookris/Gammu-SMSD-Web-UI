<?php /** @var array $plan @var bool $oob */ $r = $plan['recipients']; ?>
<div id="compose-rcpt"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<p class="row gap-lg"><?= icon('users-group-two-rounded') ?><strong>Odbiorców: <?= $plan['count'] ?></strong>
<?php $notes = [];
if ($r['duplicates'] > 0) { $notes[] = $r['duplicates'] . ' ' . plural($r['duplicates'], 'duplikat pominięty', 'duplikaty pominięte', 'duplikatów pominiętych'); }
if ($r['invalid'] !== []) { $notes[] = count($r['invalid']) . ' ' . plural(count($r['invalid']), 'błędny numer', 'błędne numery', 'błędnych numerów'); } ?>
<?php if ($notes): ?><span class="muted">(<?= e(implode(' · ', $notes)) ?>)</span><?php endif ?></p>
<?php if ($r['invalid'] !== []): ?>
<div class="alert alert-err tight" role="alert"><?= icon('danger-circle') ?><div><strong>Popraw <?= count($r['invalid']) ?> <?= plural(count($r['invalid']), 'numer', 'numery', 'numerów') ?> – do tego czasu nic nie zostanie wysłane.</strong>
<ul><?php foreach ($r['invalid'] as [$raw, $why]): ?><li><code><?= e($raw) ?></code> – <?= e($why) ?> <button type="button" class="link-btn" data-remove-number="<?= e($raw) ?>" data-target="numbers">usuń</button></li><?php endforeach ?></ul></div></div>
<?php endif ?>
<?php foreach ($plan['warnings'] as $w): ?><?= alert('warn', '', $w) ?><?php endforeach ?>
</div>
