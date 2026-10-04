<?php /** @var string $tab @var ?GammuConf $conf @var string $path @var ?int $mtime @var array $backups @var ?array $pending */
$tabNames = ['form' => 'Ustawienia', 'editor' => 'Edytor pliku', 'backups' => 'Kopie zapasowe (' . count($backups) . ')', 'service' => 'Usługa'];
?>
<header class="page-head"><div><h1>Konfiguracja Gammu</h1><p class="sub"><code><?= e($path) ?></code><?= $mtime ? ' · ostatnia zmiana: ' . e(date('d.m.Y H:i', $mtime)) : '' ?></p></div>
<?php if ($tab === 'form' || $tab === 'editor'): ?><div class="actions"><button type="submit" form="conf-form" name="action" value="<?= e($tab) ?>"><?= icon('diskette') ?>Zapisz zmiany</button></div><?php endif ?>
</header>
<nav class="tabs" aria-label="Zakładki"><?php foreach ($tabNames as $k => $label): ?><a href="<?= e(url('config', ['tab' => $k === 'form' ? null : $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?></nav>
<?= flashes_html() ?>
<?php if ($conf === null && $tab !== 'service'): ?>
<?= alert('err', 'Nie można odczytać ' . $path . '.', 'Sprawdź uprawnienia: plik powinien należeć do root:www-data z prawami 0660.', '', false) ?>
<?php else: ?>
<?= view('config-' . $tab, get_defined_vars()) ?>
<?php endif ?>
<?php if ($pending !== null): ?>
<dialog id="confirm-save" aria-labelledby="confirm-save-t" data-autoopen>
<article>
<div class="dlg-head"><span class="dlg-icon"><?= icon('danger-triangle', 'icon-lg') ?></span>
<div><h2 id="confirm-save-t">Zapisujesz główny plik konfiguracyjny Gammu SMSD (<code><?= e($path) ?></code>)</h2>
<p>Błędna konfiguracja może sprawić, że Gammu nie uruchomi się lub przestanie wysyłać i odbierać SMS. Przed zapisem zostanie utworzona kopia zapasowa, którą przywrócisz w zakładce „Kopie zapasowe”.</p></div></div>
<form method="post" action="<?= e(url('config', ['tab' => $pending['tab']])) ?>"><?= csrf_field() ?>
<input type="hidden" name="token" value="<?= e($pending['token']) ?>">
<div class="dlg-body">
<p class="small"><strong>Zmiany</strong> – <?= e($pending['note']) ?></p>
<?php if ($pending['changed'] > 0): ?><pre class="codebox"><?= Diff::html($pending['diff']) ?></pre>
<?php else: ?><p class="muted small">Treść pliku bez widocznych zmian (zmieniono tylko wartości maskowane albo nic).</p><?php endif ?>
<?php if ($pending['stale']): ?><?= alert('err', 'Plik zmienił się od otwarcia formularza.', 'Anuluj i zapisz ponownie.') ?><?php endif ?>
<?php if ($pending['validation'] === []): ?><p class="status-line gap-lg"><?= icon('check-circle') ?>Walidacja: bez uwag.</p>
<?php else: ?><ul class="health gap-lg"><?php foreach ($pending['validation'] as [$lvl, $txt]): ?><li class="<?= e($lvl) ?>"><?= icon($lvl === 'err' ? 'danger-circle' : 'danger-triangle') ?><span><?= e($txt) ?></span></li><?php endforeach ?></ul><?php endif ?>
<fieldset class="gap-lg"><legend>Po zapisie</legend>
<label><input type="radio" name="after" value="reload" checked> Przeładuj Gammu (zalecane)</label>
<label><input type="radio" name="after" value="restart"> Zrestartuj usługę</label>
<label><input type="radio" name="after" value="none"> Nie rób nic</label></fieldset>
</div>
<footer><button type="submit" name="action" value="discard" class="secondary outline" formnovalidate>Anuluj</button>
<button type="submit" name="action" value="confirm" class="btn-warn"<?= $pending['blocked'] || $pending['stale'] ? ' disabled' : '' ?>>Zapisz konfigurację</button></footer>
</form>
</article>
</dialog>
<?php endif ?>
