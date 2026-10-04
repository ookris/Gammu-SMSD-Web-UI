<?php /** @var array $f @var ?string $path @var array $lines @var ?int $size @var ?int $mtime @var bool $readable */
$sel = static fn (string $a, string $b) => $a === $b ? ' selected' : '';
?>
<header class="page-head"><div><h1>Log Gammu</h1><p class="sub"><?php if ($path): ?><code><?= e($path) ?></code><?= $size !== null ? ' · ' . e(fmt_bytes($size)) . ' · zmieniony ' . e(fmt_ago(date('Y-m-d H:i:s', (int) $mtime))) : '' ?><?php else: ?>brak parametru logfile w gammu-smsdrc<?php endif ?></p></div>
<div class="actions"><a href="<?= e(current_url()) ?>" role="button" class="secondary"><?= icon('refresh') ?>Odśwież</a></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./" data-autosubmit><input type="hidden" name="p" value="log">
<div class="field"><label for="lines">Linie</label><select id="lines" name="lines"><?php foreach (['100', '500', '2000'] as $n): ?><option value="<?= $n ?>"<?= $sel($n, $f['lines']) ?>>ostatnie <?= $n ?></option><?php endforeach ?></select></div>
<div class="field"><label for="order">Kolejność</label><select id="order" name="order"><option value="desc"<?= $sel('desc', $f['order']) ?>>najnowsze na górze</option><option value="asc"<?= $sel('asc', $f['order']) ?>>najnowsze na dole</option></select></div>
<div class="field grow"><label for="q">Filtr</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="np. +48601 albo Error"></div>
<label><input type="checkbox" name="hl" value="1"<?= $f['hl'] !== '' ? ' checked' : '' ?>> <span>Podświetlaj błędy</span></label>
<label><input type="checkbox" name="auto" value="1"<?= $f['auto'] !== '' ? ' checked' : '' ?>> <span>Odświeżaj co 5 s</span></label>
</form>
</section>
<div class="gap-lg"></div>
<?php if (!$readable): ?>
<?= alert('warn', 'Panel nie może odczytać logu Gammu.', $path ? 'Nadaj grupie www-data prawo odczytu: chgrp www-data ' . $path . ' && chmod g+r ' . $path . ' (także w regule logrotate).' : 'Ustaw logfile w konfiguracji Gammu.', '', false) ?>
<?php else: ?>
<?= view('log-lines', get_defined_vars()) ?>
<p class="muted small gap-lg">Linie z „Error” i „Failed” są podświetlone. Plik jest czytany od końca – duży log nie spowalnia panelu.</p>
<?php endif ?>
