<?php /** @var array $rows @var int $total @var int $page @var string $q @var string $group @var array $groups @var int $count @var int $noGroup */ ?>
<header class="page-head"><div><h1>Kontakty</h1><p class="sub">Książka telefoniczna · <?= $count ?> <?= plural($count, 'kontakt', 'kontakty', 'kontaktów') ?></p></div>
<div class="actions"><a href="<?= e(url('contacts', ['import' => 1])) ?>" role="button" class="secondary"><?= icon('upload') ?>Import CSV</a><a href="<?= e(url('contacts', ['export' => 1])) ?>" role="button" class="secondary"><?= icon('download') ?>Eksport CSV</a><a href="<?= e(url('contact')) ?>" role="button"><?= icon('user-plus') ?>Dodaj kontakt</a></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./" data-autosubmit>
<input type="hidden" name="p" value="contacts">
<div class="field grow"><label for="q">Szukaj</label>
<input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="nazwa lub numer – wyniki podczas pisania" autocomplete="off"
       hx-get="./" hx-trigger="input changed delay:300ms, search" hx-target="#contacts-results" hx-include="closest form" hx-push-url="true"></div>
<div class="field"><label for="group">Grupa</label><select id="group" name="group">
<option value="">Wszystkie (<?= $count ?>)</option>
<?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $group === (string) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['members'] ?>)</option><?php endforeach ?>
<option value="none"<?= $group === 'none' ? ' selected' : '' ?>>Bez grupy (<?= $noGroup ?>)</option></select></div>
</form>
<div id="contacts-results"><?= view('contacts-results', get_defined_vars()) ?></div>
</section>
<p class="muted small gap-lg">Import CSV: kolumny <code>nazwa;numer;grupy;notatka</code> (grupy oddzielone <code>|</code>), UTF-8, separator <code>;</code> lub <code>,</code>. Przed importem zobaczysz podgląd: nowe, aktualizacje, błędne wiersze.</p>
