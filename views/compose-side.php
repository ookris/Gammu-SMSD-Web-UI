<?php
/** @var array $in @var array $plan @var bool $oob */
$confirm ??= false;
$a = $plan['analysis'];
$coding = $a['gsm'] ? 'GSM-7' : 'Unicode';
?>
<div class="stack" id="compose-side">
<section class="card">
<header><h2>Podsumowanie</h2></header>
<dl class="kv">
<dt>Odbiorcy</dt><dd><?= $plan['count'] ?></dd>
<dt>Części</dt><dd><?= $plan['parts'] ?> na wiadomość · <?= e($coding) ?></dd>
<dt>Razem</dt><dd><strong><?= $plan['count'] ?> × <?= $plan['parts'] ?> = <?= $plan['total'] ?> SMS</strong></dd>
<?php if ($plan['multi']): ?><dt>Tempo</dt><dd><?= $plan['per_min'] > 0 ? $plan['per_min'] . ' SMS/min · ok. ' . e(fmt_duration(max(60, $plan['duration']))) : 'bez dławienia' ?></dd><?php endif ?>
<?php if ($plan['delayed']): ?><dt>Start</dt><dd><?= e(Compose::startLabel($plan)) ?></dd><?php endif ?>
</dl>
<div class="stack-sm gap-lg">
<?php if ($plan['multi']): ?>
<button type="button" data-dialog-open="confirm-send"><?= icon('plain') ?>Wyślij do <?= $plan['count'] ?> odbiorców</button>
<?php else: ?>
<button type="submit" name="send" value="1"><?= icon('plain') ?>Wyślij</button>
<?php endif ?>
<button type="submit" name="save_template" value="1" class="secondary outline" formnovalidate><?= icon('diskette') ?>Zapisz jako szablon</button>
</div>
</section>
<?php if ($plan['preview'] !== null): ?>
<section class="card"><header><h2>Podgląd</h2></header>
<p class="muted small">Tak zobaczy to: <?= e($plan['preview']['name']) ?></p>
<div class="preview-bubble"><?= nl2br(e($plan['preview']['text'])) ?></div></section>
<?php endif ?>
<?php if ($plan['multi']): ?>
<dialog id="confirm-send" aria-labelledby="confirm-send-t"<?= $confirm ? ' data-autoopen' : '' ?>>
<article class="dlg-sm">
<div class="dlg-head"><span class="dlg-icon accent"><?= icon('plain', 'icon-lg') ?></span>
<div><h2 id="confirm-send-t">Wysłać <?= $plan['total'] ?> SMS do <?= $plan['count'] ?> odbiorców?</h2>
<p>Postęp wysyłki zobaczysz w raporcie – tam też ponowisz nieudane albo anulujesz pozostałe.</p></div></div>
<div class="dlg-body"><dl class="kv kv-170">
<dt>Odbiorcy</dt><dd><?= $plan['count'] ?><?= $plan['recipients']['duplicates'] ? ' (' . $plan['recipients']['duplicates'] . ' ' . plural($plan['recipients']['duplicates'], 'duplikat pominięty', 'duplikaty pominięte', 'duplikatów pominiętych') . ')' : '' ?></dd>
<dt>Wiadomości</dt><dd><?= $plan['total'] ?> SMS · <?= $plan['parts'] ?> <?= plural($plan['parts'], 'część', 'części', 'części') ?> · <?= e($coding) ?></dd>
<dt>Tempo</dt><dd><?= $plan['per_min'] > 0 ? $plan['per_min'] . ' SMS/min – ok. ' . e(fmt_duration(max(60, $plan['duration']))) : 'bez dławienia' ?></dd>
<dt>Start</dt><dd><?= e(Compose::startLabel($plan)) ?><?= $plan['delayed'] && $plan['send_at'] === null ? ' (okno wysyłki)' : '' ?></dd>
<dt>Raport doręczenia</dt><dd><?= $in['report'] ? 'tak' : 'nie' ?></dd>
<?php $notes = [];
if ($plan['recipients']['blocked']) { $notes[] = count($plan['recipients']['blocked']) . ' z czarnej listy'; }
if ($plan['vars_missing']) { $notes[] = $plan['vars_missing'] . ' bez {imie}'; }
if ($plan['errors']) { $notes[] = 'formularz ma błędy – wysyłka zostanie wstrzymana'; } ?>
<?php if ($notes): ?><dt>Uwagi</dt><dd><?= e(implode(' · ', $notes)) ?></dd><?php endif ?>
</dl></div>
<footer><button type="button" class="secondary outline" data-dialog-close>Anuluj</button>
<button type="submit" form="compose-form" name="confirm" value="1"><?= icon('plain') ?>Wyślij</button></footer>
</article>
</dialog>
<?php endif ?>
</div>
<?php if ($oob): ?>
<?= view('compose-rcpt', ['plan' => $plan, 'oob' => true]) ?>
<?= view('compose-vars', ['plan' => $plan, 'oob' => true]) ?>
<?= view('compose-window', ['plan' => $plan, 'in' => $in, 'oob' => true]) ?>
<?php endif ?>
