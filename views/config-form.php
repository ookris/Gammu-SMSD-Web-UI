<?php /** @var GammuConf $conf @var array $values @var array $ports @var array $fieldErrors */ ?>
<form method="post" action="<?= e(url('config')) ?>" id="conf-form"><?= csrf_field() ?><input type="hidden" name="base" value="<?= e(is_post() && input('base') !== '' ? input('base') : $base) ?>">
<div class="stack">
<?php foreach (GammuConfForm::cards() as $title => $fields): ?>
<section class="card"><header><h2><?= e($title) ?></h2></header>
<div class="<?= count($fields) > 4 ? 'grid-3' : 'grid-2' ?>">
<?php foreach ($fields as $f): [$section, $key, $type, $desc] = $f;
    $name = $section . '_' . $key;
    $current = $conf->get($section, $key);
    $value = array_key_exists($name, $values) ? (string) $values[$name] : ($type === 'password' && $current !== null && $current !== '' ? GammuConf::MASK : (string) $current);
    $err = $fieldErrors[$name] ?? null;
    $warn = $key === 'deliveryreportdelay' && ($current === null || (int) $current < 3600);
    $hint = $err ?? ($warn ? 'Zalecane: 172800 (2 dni). Przy ' . ($current ?? '600') . ' s raport od telefonu wyłączonego dłużej niż ' . (int) ceil(((int) ($current ?? 600)) / 60) . ' min nie zostanie dopasowany.' : $desc); ?>
<div class="field"><label for="<?= e($name) ?>"><?= e($key) ?></label>
<?php if ($type === 'select'): ?>
<select id="<?= e($name) ?>" name="<?= e($name) ?>" aria-describedby="<?= e($name) ?>-h">
<?php if (!array_key_exists($value, $f[4])): ?><option value="<?= e($value) ?>" selected><?= e($value) ?></option><?php endif ?>
<?php foreach ($f[4] as $v => $label): ?><option value="<?= e((string) $v) ?>"<?= (string) $v === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
<?php elseif ($type === 'device'): ?>
<input id="<?= e($name) ?>" name="<?= e($name) ?>" type="text" value="<?= e($value) ?>" class="mono-input" list="ports" aria-describedby="<?= e($name) ?>-h">
<datalist id="ports"><?php foreach ($ports as $p): ?><option value="<?= e($p) ?>"><?= e(basename($p)) ?></option><?php endforeach ?></datalist>
<?php $hint = $err ?? ('Wykryte porty w ' . cfg('serial_dir') . ': ' . count($ports) . ($ports ? ' – wybierz z listy albo wpisz ręcznie' : '')); ?>
<?php else: ?>
<input id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $type === 'password' ? 'password' : 'text' ?>" value="<?= e($value) ?>" class="mono-input"<?= $type === 'number' ? ' inputmode="numeric"' : '' ?><?= $type === 'password' ? ' autocomplete="off"' : '' ?> aria-describedby="<?= e($name) ?>-h"<?= $err ? ' aria-invalid="true"' : '' ?>>
<?php endif ?>
<small id="<?= e($name) ?>-h"<?= $err || $warn ? ' class="warn"' : '' ?>><?= e($hint) ?></small></div>
<?php endforeach ?>
</div></section>
<?php endforeach ?>
<?= alert('info', '', 'Parametry zarządzane przez panel (service, dane bazy, HangupCalls, RunOnIncomingCall, ExcludeNumbersFile) nie występują w formularzu. Puste pole = parametr usuwany z pliku (Gammu użyje wartości domyślnej).') ?>
<div><button type="submit" name="action" value="form"><?= icon('diskette') ?>Zapisz zmiany</button></div>
</div>
</form>
