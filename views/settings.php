<?php /** @var callable $v @var array $errors @var array $codes @var bool $callsEnabled @var array $system */
$chk = static fn (string $k) => $v($k) === '1' || (is_post() && isset($_POST[$k])) ? ' checked' : '';
$err = static fn (string $k) => isset($errors[$k]) ? '<small class="warn">' . e($errors[$k]) . '</small>' : '';
?>
<header class="page-head"><div><h1>Ustawienia panelu</h1><p class="sub">Zapisywane w bazie panelu</p></div>
<div class="actions"><button type="submit" form="settings-form"><?= icon('diskette') ?>Zapisz ustawienia</button></div></header>
<?= flashes_html() ?>
<?php if ($errors): ?><?= alert('err', 'Nie zapisano – popraw zaznaczone pola.') ?><?php endif ?>
<form id="settings-form" method="post" action="<?= e(url('settings')) ?>" class="split split-480"><?= csrf_field() ?>
<div class="stack">
<section class="card"><header><h2>Wysyłka</h2></header>
<div class="stack-sm">
<div class="grid-2">
<label><input type="checkbox" name="report_default" value="1"<?= $chk('report_default') ?>> <span>Raport doręczenia – domyślnie włączony</span></label>
<label><input type="checkbox" name="translit_default" value="1"<?= $chk('translit_default') ?>> <span>Zamiana polskich znaków – domyślnie włączona</span></label></div>
<div class="grid-2">
<div class="field"><label for="throttle">Dławienie wysyłki do wielu (SMS na minutę)</label>
<input id="throttle" aria-describedby="throttle-h" name="throttle_per_min" type="number" value="<?= e($v('throttle_per_min')) ?>" min="0" max="60">
<small id="throttle-h">0 = bez limitu · operatorzy mogą zablokować kartę za nagłe wysłanie wielu SMS</small><?= $err('throttle_per_min') ?></div>
<fieldset><legend>Okno wysyłki do wielu odbiorców</legend>
<label><input type="checkbox" name="window_enabled" value="1"<?= $chk('window_enabled') ?>> <span>Włączone</span></label>
<div class="inline-pair"><label for="win-from" class="visually-hidden">Od</label><input id="win-from" name="window_from" type="time" value="<?= e($v('window_from')) ?>"><span>–</span>
<label for="win-to" class="visually-hidden">Do</label><input id="win-to" name="window_to" type="time" value="<?= e($v('window_to')) ?>"></div>
<small<?= isset($errors['window']) ? ' class="warn"' : '' ?>><?= e($errors['window'] ?? 'Okno nie może przechodzić przez północ (ograniczenie Gammu)') ?></small></fieldset>
</div></div></section>
<section class="card"><header><h2>Szybkie kody USSD</h2></header>
<div class="stack-sm" id="ussd-rows">
<?php foreach ($codes ?: [['name' => '', 'code' => '']] as $c): ?>
<div class="row"><input name="ussd_name[]" aria-label="Nazwa kodu" value="<?= e($c['name']) ?>" class="grow-1" placeholder="np. Saldo"><input name="ussd_code[]" aria-label="Kod USSD" value="<?= e($c['code']) ?>" class="mono-input code-input" placeholder="*101#">
<button type="button" class="icon-btn danger" aria-label="Usuń kod" title="Usuń kod" data-remove-row><?= icon('trash-bin-trash') ?></button></div>
<?php endforeach ?>
</div>
<?= $err('ussd') ?>
<template id="ussd-row-tpl"><div class="row"><input name="ussd_name[]" aria-label="Nazwa kodu" class="grow-1" placeholder="np. Saldo"><input name="ussd_code[]" aria-label="Kod USSD" class="mono-input code-input" placeholder="*101#">
<button type="button" class="icon-btn danger" aria-label="Usuń kod" title="Usuń kod" data-remove-row><?= icon('trash-bin-trash') ?></button></div></template>
<div class="gap-lg"><button type="button" class="secondary outline btn-sm" data-add-row="ussd-row-tpl" data-target="ussd-rows"><?= icon('add-circle') ?>Dodaj kod</button></div>
</section>
<section class="card"><header><h2>Połączenia przychodzące</h2></header>
<label><input type="checkbox" name="calls_enabled" value="1"<?= ($callsEnabled && !is_post()) || isset($_POST['calls_enabled']) ? ' checked' : '' ?>> <span>Odrzucaj połączenia przychodzące i zapisuj je w panelu<span class="check-sub">Gammu nie pozwala zapisywać połączeń bez ich odrzucania – każdy dzwoniący usłyszy rozłączenie. Zmiana dopisuje HangupCalls i RunOnIncomingCall do gammu-smsdrc przez okno potwierdzenia zapisu.</span></span></label>
</section>
<section class="card"><header><h2>Sesja, kopie zapasowe i historia</h2></header>
<div class="grid-3">
<div class="field"><label for="session_hours">Wygaśnięcie sesji po bezczynności (godziny)</label><input id="session_hours" name="session_hours" type="number" min="1" max="720" value="<?= e($v('session_hours')) ?>"><?= $err('session_hours') ?></div>
<div class="field"><label for="backup_keep">Liczba kopii zapasowych gammu-smsdrc</label><input id="backup_keep" name="backup_keep" type="number" min="1" max="500" value="<?= e($v('backup_keep')) ?>"><?= $err('backup_keep') ?></div>
<div class="field"><label for="cleanup_days">Usuwaj historię starszą niż (dni)</label><input id="cleanup_days" aria-describedby="cleanup-h" name="cleanup_days" type="number" min="0" max="3650" value="<?= e($v('cleanup_days')) ?>"><small id="cleanup-h">0 = nigdy · raz dziennie: wiadomości, połączenia, USSD i zaimportowane wiersze Gammu</small><?= $err('cleanup_days') ?></div>
</div></section>
</div>
<section class="card"><header><h2>Ustawienia systemowe</h2><span class="badge">tylko do odczytu</span></header>
<dl class="kv kv-170"><?php foreach ($system as $k => $val): ?><dt><?= e($k) ?></dt><dd><span class="mono"><?= e($val) ?></span></dd><?php endforeach ?></dl>
<p class="muted small gap-lg">Zmienisz je tylko w pliku <code>config/config.php</code> na serwerze. Hasła nie są wyświetlane.</p>
</section>
</form>
