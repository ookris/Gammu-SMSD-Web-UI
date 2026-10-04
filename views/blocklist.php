<?php /** @var array $rows @var string $q @var bool $enabled @var bool $inSync @var ?string $written @var ?string $reload */ ?>
<header class="page-head"><div><h1>Zablokowane numery</h1><p class="sub">SMS od tych nadawców nie trafiają do panelu – Gammu usuwa je z modemu (<code>ExcludeNumbersFile</code>)</p></div>
<div class="actions"><a href="#import-blocklist" data-dialog-open="import-blocklist" role="button" class="secondary"><?= icon('upload') ?>Import CSV</a><a href="<?= e(url('blocklist', ['export' => 1])) ?>" role="button" class="secondary"><?= icon('download') ?>Eksport CSV</a></div></header>
<?= flashes_html() ?>
<?php if (!$enabled): ?>
<form method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<?= alert('warn', 'Czarna lista nie jest włączona w konfiguracji Gammu.', 'Panel prowadzi listę, ale Gammu jej nie czyta, dopóki w gammu-smsdrc nie ma ExcludeNumbersFile = ' . Blocklist::path() . '. Włączenie przejdzie przez okno potwierdzenia zapisu.', '<button type="submit" name="enable" value="1" class="btn-sm">Włącz czarną listę</button>', false) ?>
</form>
<?php endif ?>
<section class="card">
<header><h2>Dodaj do listy</h2></header>
<form class="row row-end" method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<div class="field grow"><label for="bphone">Numer lub nazwa nadawcy</label><input id="bphone" name="phone" type="text" placeholder="np. 515 330 921 albo PROMO-SMS" required></div>
<div class="field grow"><label for="bnote">Notatka (opcjonalnie)</label><input id="bnote" name="note" type="text" placeholder="np. spam"></div>
<button type="submit"><?= icon('forbidden-circle') ?>Zablokuj</button></form>
<p class="muted small gap-lg">Numer zostanie zapisany w formacie międzynarodowym (w pliku – w kilku wariantach zapisu); nazwy alfanumeryczne – bez zmian. Gammu zostanie przeładowany automatycznie.</p>
</section>
<div class="gap-lg"></div>
<section class="card flush">
<form class="toolbar" method="get" action="./"><input type="hidden" name="p" value="blocklist">
<div class="field grow"><label for="q">Szukaj</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="numer, nazwa lub notatka"></div>
<span class="status-line"><?= icon($inSync ? 'check-circle' : 'danger-triangle') ?><?= $inSync ? 'Plik listy zapisany' . ($written ? ' ' . e(fmt_when($written)) : '') . ($reload === 'ok' ? ' · Gammu przeładowany' : '') : 'Plik listy niezgodny z bazą – dodaj lub usuń numer, żeby zapisać go ponownie' ?></span>
</form>
<?php if ($rows === []): ?><?= Ui::empty('forbidden-circle', $q !== '' ? 'Brak wyników' : 'Lista jest pusta', $q !== '' ? 'Zmień frazę.' : 'Zablokuj nadawcę spamu tutaj albo w rozmowie, odebranych lub połączeniach.') ?><?php else: ?>
<form method="post" action="<?= e(url('blocklist')) ?>"><?= csrf_field() ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col">Numer / nadawca</th><th scope="col">Kontakt</th><th scope="col">Notatka</th><th scope="col">Dodano</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td><span class="mono"><?= e(Phone::isAlpha($r['phone']) ? $r['phone'] : Phone::toGammu($r['phone'])) ?></span><?= Phone::isAlpha($r['phone']) ? ' <span class="badge">nazwa</span>' : '' ?></td>
<td><?= $r['contact_id'] ? '<a href="' . e(url('contact', ['id' => $r['contact_id']])) . '">' . e($r['contact_name']) . '</a>' : '<span class="muted">—</span>' ?></td>
<td><?= $r['note'] !== '' ? e($r['note']) : '<span class="muted">—</span>' ?></td><td><?= e(date('d.m.Y', (int) ts($r['created_at']))) ?></td>
<td><button type="submit" name="unblock" value="<?= (int) $r['id'] ?>" class="secondary outline btn-sm">Odblokuj</button></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<div class="pager"><span><?= count($rows) ?> <?= plural(count($rows), 'pozycja', 'pozycje', 'pozycji') ?></span><nav aria-label="Strony"></nav></div>
<?php endif ?>
</section>
<p class="muted small gap-lg">Połączenia z zablokowanych numerów są nadal odrzucane i zapisywane, ale bez automatyzacji. Format CSV: <code>numer;notatka</code>.</p>
<dialog id="import-blocklist" aria-labelledby="import-blocklist-t"><article class="dlg-sm">
<div class="dlg-head"><span class="dlg-icon accent"><?= icon('upload', 'icon-lg') ?></span><div><h2 id="import-blocklist-t">Import czarnej listy</h2><p>Plik CSV z kolumnami <code>numer;notatka</code>.</p></div></div>
<form method="post" action="<?= e(url('blocklist')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="dlg-body"><input name="csv" type="file" accept=".csv,text/csv,text/plain" required aria-label="Plik CSV"></div>
<footer><button type="button" class="secondary outline" data-dialog-close>Anuluj</button><button type="submit"><?= icon('import') ?>Importuj</button></footer></form>
</article></dialog>
