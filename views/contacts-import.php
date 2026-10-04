<?php /** @var ?array $preview */ ?>
<header class="page-head"><div><h1>Import kontaktów</h1><p class="sub">Plik CSV · <a href="<?= e(url('contacts')) ?>">wróć do listy</a></p></div></header>
<?= flashes_html() ?>
<?php if ($preview === null): ?>
<section class="card narrow">
<form class="stack-sm" method="post" action="<?= e(url('contacts')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="field"><label for="csv">Plik CSV</label><input id="csv" name="csv" type="file" accept=".csv,text/csv,text/plain" required>
<small>Kolumny: <code>nazwa;numer;grupy;notatka</code> – grupy oddzielone <code>|</code>. UTF-8 (także z BOM, np. z Excela), separator <code>;</code> lub <code>,</code> wykrywany automatycznie. Kontakty dopasowywane są po numerze.</small></div>
<div><button type="submit"><?= icon('eye') ?>Pokaż podgląd</button></div>
</form>
</section>
<?php else: ?>
<section class="card">
<header><h2>Podgląd importu</h2></header>
<div class="stat-row"><span class="badge badge-ok"><?= $preview['new'] ?> nowe</span><span class="badge badge-info"><?= $preview['updated'] ?> aktualizacje</span><span class="badge badge-err"><?= count($preview['errors']) ?> błędne wiersze</span></div>
<?php if ($preview['errors']): ?><ul class="gap-lg small"><?php foreach (array_slice($preview['errors'], 0, 50) as [$line, $why]): ?><li>Linia <?= (int) $line ?>: <?= e($why) ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($preview['rows']): ?>
<div class="table-wrap gap-lg"><table><thead><tr><th>Nazwa</th><th>Numer</th><th>Grupy</th><th>Notatka</th></tr></thead><tbody>
<?php foreach (array_slice($preview['rows'], 0, 20) as $r): ?><tr><td><?= e($r['name']) ?></td><td class="mono"><?= e(Phone::format($r['phone'])) ?></td><td><?= e(implode(', ', $r['groups'])) ?></td><td><?= e($r['note']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php if (count($preview['rows']) > 20): ?><p class="muted small">… i <?= count($preview['rows']) - 20 ?> kolejnych.</p><?php endif ?>
<?php endif ?>
<form class="row gap-lg" method="post" action="<?= e(url('contacts')) ?>"><?= csrf_field() ?>
<button type="submit" name="import_confirm" value="1"<?= $preview['rows'] ? '' : ' disabled' ?>><?= icon('import') ?>Importuj <?= count($preview['rows']) ?> <?= plural(count($preview['rows']), 'kontakt', 'kontakty', 'kontaktów') ?></button>
<a href="<?= e(url('contacts', ['import' => 1, 'cancel' => 1])) ?>" role="button" class="secondary outline">Anuluj</a></form>
</section>
<?php endif ?>
