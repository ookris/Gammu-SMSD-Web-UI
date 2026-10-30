<?php /** @var array $daily @var array $monthly @var array $delivery @var array $top @var int $calls @var ?array $limit @var array $totals */
$dayLabels = array_map(static fn ($r) => date('d.m', strtotime($r['day'])), $daily);
$monthLabels = array_map(static fn ($r) => date('m.Y', strtotime($r['month'] . '-01')), $monthly);
$legend = '<ul class="chart-legend"><li><i class="k1"></i>' . e(t('stats.sent')) . '</li><li><i class="k2"></i>' . e(t('stats.received')) . '</li></ul>';
?>
<header class="page-head"><div><h1><?= e(t('nav.stats')) ?></h1><p class="sub"><?= e(t('stats.sub')) ?></p></div></header>
<?= flashes_html() ?>
<div class="stat-grid">
<div class="stat"><strong><?= (int) $totals['out'] ?></strong><span><?= e(t('stats.sent_30')) ?></span></div>
<div class="stat"><strong><?= (int) $totals['in'] ?></strong><span><?= e(t('stats.received_30')) ?></span></div>
<div class="stat"><strong><?= $delivery['pct'] !== null ? e(number_format($delivery['pct'], 1, lang() === 'pl' ? ',' : '.', '')) . ' %' : '—' ?></strong><span><?= e(t('stats.delivery', ['d' => $delivery['delivered'], 'n' => $delivery['delivered'] + $delivery['undelivered']])) ?></span></div>
<div class="stat"><strong><?= (int) $calls ?></strong><span><?= e(t('stats.calls_30')) ?></span></div>
</div>
<?php if ($limit !== null): ?>
<section class="card">
<header><h2><?= e(t('stats.limit')) ?></h2><span class="muted"><?= e(t('stats.limit_of', ['used' => $limit['used'], 'limit' => $limit['limit']])) ?></span></header>
<progress class="signal" value="<?= min(100, $limit['pct']) ?>" max="100" aria-label="<?= e(t('stats.limit_label', ['pct' => $limit['pct']])) ?>"></progress> <?= $limit['pct'] ?> %
</section>
<div class="gap-lg"></div>
<?php endif ?>
<section class="card">
<header><h2><?= e(t('stats.daily')) ?></h2></header>
<?= $legend ?>
<?= Chart::bars(t('stats.daily'), t('stats.daily_desc'), $dayLabels, [t('stats.sent') => array_column($daily, 'out'), t('stats.received') => array_column($daily, 'in')]) ?>
</section>
<div class="gap-lg"></div>
<section class="card">
<header><h2><?= e(t('stats.monthly')) ?></h2></header>
<?= $legend ?>
<?= Chart::bars(t('stats.monthly'), t('stats.monthly_desc'), $monthLabels, [t('stats.sent') => array_column($monthly, 'out'), t('stats.received') => array_column($monthly, 'in')]) ?>
<details class="gap-lg"><summary><?= e(t('stats.as_table')) ?></summary>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('stats.month')) ?></th><th scope="col"><?= e(t('stats.sent')) ?></th><th scope="col"><?= e(t('stats.received')) ?></th></tr></thead>
<tbody><?php foreach (array_reverse($monthly) as $i => $r): ?><tr><td><?= e(date('m.Y', strtotime($r['month'] . '-01'))) ?></td><td><?= (int) $r['out'] ?></td><td><?= (int) $r['in'] ?></td></tr><?php endforeach ?></tbody></table></div>
</details>
</section>
<div class="gap-lg"></div>
<section class="card flush">
<header><h2><?= e(t('stats.top')) ?></h2></header>
<?php if ($top === []): ?><?= Ui::empty('chart-2', t('stats.no_data'), '') ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('stats.who')) ?></th><th scope="col"><?= e(t('stats.sent')) ?></th><th scope="col"><?= e(t('stats.received')) ?></th></tr></thead>
<tbody><?php foreach ($top as $r): ?><tr><td><a href="<?= e(url('inbox', ['phone' => $r['phone']])) ?>"><?= e(Contacts::display($r['phone'])) ?></a></td><td><?= $r['out'] ?></td><td><?= $r['in'] ?></td></tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
</section>
