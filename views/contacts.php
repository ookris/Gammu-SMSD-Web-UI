<?php /** @var array $rows @var int $total @var int $page @var string $q @var string $group @var array $groups @var int $count @var int $noGroup */ ?>
<header class="page-head"><div><h1><?= e(t('nav.contacts')) ?></h1><p class="sub"><?= e(t('contacts.sub', ['count' => tn('contacts.count', $count)])) ?></p></div>
<div class="actions"><a href="<?= e(url('contacts', ['import' => 1])) ?>" role="button" class="secondary"><?= icon('upload') ?><?= e(t('blocklist.import_csv')) ?></a><a href="<?= e(url('contacts', ['export' => 1])) ?>" role="button" class="secondary"><?= icon('download') ?><?= e(t('blocklist.export_csv')) ?></a><a href="<?= e(url('contact')) ?>" role="button"><?= icon('user-plus') ?><?= e(t('contacts.add')) ?></a></div></header>
<?= flashes_html() ?>
<section class="card flush">
<form class="toolbar" method="get" action="./" data-autosubmit>
<input type="hidden" name="p" value="contacts">
<div class="field grow"><label for="q"><?= e(t('common.search')) ?></label>
<input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(t('contacts.search_ph')) ?>" autocomplete="off"
       hx-get="./" hx-trigger="input changed delay:300ms, search" hx-target="#contacts-results" hx-include="closest form" hx-push-url="true"></div>
<div class="field"><label for="group"><?= e(t('contacts.group')) ?></label><select id="group" name="group">
<option value=""><?= e(t('contacts.all_n', ['n' => $count])) ?></option>
<?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $group === (string) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['members'] ?>)</option><?php endforeach ?>
<option value="none"<?= $group === 'none' ? ' selected' : '' ?>><?= e(t('contacts.no_group_n', ['n' => $noGroup])) ?></option></select></div>
</form>
<div id="contacts-results"><?= view('contacts-results', get_defined_vars()) ?></div>
</section>
<p class="muted small gap-lg"><?= t('contacts.import_hint', ['columns' => '<code>' . e(t('contacts.csv_header')) . '</code>', 'sep' => '<code>|</code>', 'semicolon' => '<code>;</code>', 'comma' => '<code>,</code>']) ?></p>
