<?php /** @var string $id @var array $meta @var array $stats */
$running = $stats['scheduled'] + $stats['queued'] > 0;
$end = $meta['last_at'] ? date('H:i', (int) ts($meta['last_at'])) : null;
?>
<section class="card" id="batch-progress"<?= $running ? ' hx-get="' . e(url('batch', ['id' => $id, 'fragment' => 'progress'])) . '" hx-trigger="every 5s" hx-swap="outerHTML"' : '' ?>>
<header><h2>Postęp</h2><span class="muted"><?= $running ? 'odświeżanie co 5 s' : 'wysyłka zakończona' ?></span></header>
<div class="progress-row"><progress value="<?= $stats['done'] ?>" max="<?= max(1, $stats['total']) ?>" aria-label="Postęp wysyłki"></progress><strong><?= $stats['done'] ?> z <?= $stats['total'] ?></strong></div>
<div class="stat-row"><span class="badge badge-ok"><?= $stats['delivered'] ?> doręczone</span><span class="badge badge-info"><?= $stats['sent'] ?> wysłane</span><span class="badge badge-warn"><?= $stats['queued'] ?> w kolejce</span><span class="badge"><?= $stats['scheduled'] ?> zaplanowane</span><span class="badge badge-err"><?= $stats['failed'] ?> błędy</span><?php if ($stats['cancelled']): ?><span class="badge badge-neutral"><?= $stats['cancelled'] ?> anulowane</span><?php endif ?></div>
<dl class="kv kv-170 gap-lg">
<dt>Treść</dt><dd><?= nl2br(e($meta['text'])) ?></dd>
<dt>Wysłano</dt><dd><?= e(fmt_when($meta['created'])) ?> · <?= $stats['total'] ?> odbiorców × <?= (int) $meta['parts'] ?> SMS</dd>
<dt>Tempo</dt><dd><?= $meta['per_min'] ? (int) $meta['per_min'] . ' SMS/min' . ($end ? ' · koniec ok. ' . e($end) : '') : 'bez dławienia' ?></dd>
<dt>Raport doręczenia</dt><dd><?= $meta['report'] ? 'tak' : 'nie' ?></dd>
</dl>
</section>
