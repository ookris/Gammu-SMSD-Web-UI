<?php /** @var array $modems @var ?array $current @var array $codes @var array $history @var ?string $device */ ?>
<header class="page-head"><div><h1>Modem i USSD</h1><p class="sub">Stan modemu i kody USSD</p></div>
<div class="actions"><a href="<?= e(url('modem')) ?>" role="button" class="secondary"><?= icon('refresh') ?>Odśwież</a></div></header>
<?= flashes_html() ?>
<div class="split split-520">
<?= view('modem-state', ['modems' => $modems, 'device' => $device]) ?>
<section class="card">
<header><h2>USSD na żądanie</h2><a href="<?= e(url('settings')) ?>"><strong>Edytuj szybkie kody</strong></a></header>
<?php if ($codes): ?><p class="small"><strong>Szybkie kody</strong></p>
<div class="chips"><?php foreach ($codes as $c): ?><button type="button" class="secondary outline btn-sm" data-fill="ussd-code" data-value="<?= e($c['code']) ?>"><?= e($c['name']) ?> · <?= e($c['code']) ?></button><?php endforeach ?></div><?php endif ?>
<form class="row row-end gap-lg" method="post" action="<?= e(url('modem')) ?>"><?= csrf_field() ?>
<div class="field grow-1"><label for="ussd-code">Kod USSD</label><input id="ussd-code" name="code" class="mono-input" value="<?= e($codes[0]['code'] ?? '*101#') ?>" required pattern="[0-9*#+]+"></div>
<button type="submit"><?= icon('plain') ?>Wyślij</button></form>
<?= view('modem-ussd', ['current' => $current]) ?>
<p class="muted small gap-lg">Jedno oczekujące żądanie na modem. Odpowiedź zależy od modemu i operatora – panel pokazuje surowy tekst i stan sesji Gammu.</p>
</section>
</div>
<div class="gap-lg"></div>
<section class="card flush">
<header><h2>Historia USSD</h2></header>
<?php if ($history === []): ?><?= Ui::empty('sim-card', 'Brak żądań USSD', 'Wyślij kod, np. *101#, żeby sprawdzić saldo.') ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col">Data</th><th scope="col">Kod</th><th scope="col">Odpowiedź</th><th scope="col">Stan sesji</th></tr></thead>
<tbody>
<?php foreach ($history as $r): ?>
<tr><td class="nowrap"><?= e(fmt_when($r['created_at'])) ?></td><td><span class="mono"><?= e(Ussd::rootCode($r)) ?></span></td>
<td><?= $r['response'] !== null ? e(preg_replace('/\s+/u', ' ', $r['response'])) : '<span class="muted">' . e($r['status'] === 'timeout' ? 'brak odpowiedzi w ciągu ' . Ussd::TIMEOUT . ' s' : t('ussd.status.' . $r['status'])) . '</span>' ?></td>
<td><?= view('partials/ussd-badge', ['r' => $r]) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
