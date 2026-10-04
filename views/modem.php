<?php /** @var array $modems @var ?array $current @var array $codes @var array $history @var ?string $device */ ?>
<header class="page-head"><div><h1><?= e(t('nav.modem')) ?></h1><p class="sub"><?= e(t('modem.sub')) ?></p></div>
<div class="actions"><a href="<?= e(url('modem')) ?>" role="button" class="secondary"><?= icon('refresh') ?><?= e(t('modem.refresh')) ?></a></div></header>
<?= flashes_html() ?>
<div class="split split-520">
<?= view('modem-state', ['modems' => $modems, 'device' => $device]) ?>
<section class="card">
<header><h2><?= e(t('modem.ussd')) ?></h2><a href="<?= e(url('settings')) ?>"><strong><?= e(t('modem.edit_codes')) ?></strong></a></header>
<?php if ($codes): ?><p class="small"><strong><?= e(t('modem.quick_codes')) ?></strong></p>
<div class="chips"><?php foreach ($codes as $c): ?><button type="button" class="secondary outline btn-sm" data-fill="ussd-code" data-value="<?= e($c['code']) ?>"><?= e($c['name']) ?> · <?= e($c['code']) ?></button><?php endforeach ?></div><?php endif ?>
<form class="row row-end gap-lg" method="post" action="<?= e(url('modem')) ?>"><?= csrf_field() ?>
<div class="field grow-1"><label for="ussd-code"><?= e(t('settings.ussd_code')) ?></label><input id="ussd-code" name="code" class="mono-input" value="<?= e($codes[0]['code'] ?? '*101#') ?>" required pattern="[0-9*#+]+"></div>
<button type="submit"><?= icon('plain') ?><?= e(t('compose.send')) ?></button></form>
<?= view('modem-ussd', ['current' => $current]) ?>
<p class="muted small gap-lg"><?= e(t('modem.ussd_note')) ?></p>
</section>
</div>
<div class="gap-lg"></div>
<section class="card flush">
<header><h2><?= e(t('modem.history')) ?></h2></header>
<?php if ($history === []): ?><?= Ui::empty('sim-card', t('modem.history_empty'), t('modem.history_empty_text')) ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('common.date')) ?></th><th scope="col"><?= e(t('modem.col_code')) ?></th><th scope="col"><?= e(t('modem.col_response')) ?></th><th scope="col"><?= e(t('modem.col_session')) ?></th></tr></thead>
<tbody>
<?php foreach ($history as $r): ?>
<tr><td class="nowrap"><?= e(fmt_when($r['created_at'])) ?></td><td><span class="mono"><?= e(Ussd::rootCode($r)) ?></span></td>
<td><?= $r['response'] !== null ? e(preg_replace('/\s+/u', ' ', $r['status'] === 'failed' ? tr($r['response']) : $r['response'])) : '<span class="muted">' . e($r['status'] === 'timeout' ? t('modem.no_response_in', ['n' => Ussd::TIMEOUT]) : t('ussd.status.' . $r['status'])) . '</span>' ?></td>
<td><?= view('partials/ussd-badge', ['r' => $r]) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
