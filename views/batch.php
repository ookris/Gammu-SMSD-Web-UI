<?php /** @var string $id @var array $meta @var array $stats @var array $rows */ ?>
<header class="page-head"><div><h1>Raport wysyłki</h1><p class="sub"><?= e($meta['label'] ?: $stats['total'] . ' odbiorców') ?> · <?= e(fmt_when($meta['created'])) ?> · <a href="<?= e(url('sent')) ?>">wróć do wysłanych</a></p></div>
<form class="actions" method="post" action="<?= e(url('batch', ['id' => $id])) ?>"><?= csrf_field() ?>
<button type="submit" name="retry_failed" value="1" class="secondary"<?= $stats['failed'] === 0 ? ' disabled' : '' ?>><?= icon('restart') ?>Ponów nieudane (<?= $stats['failed'] ?>)</button>
<button type="submit" name="cancel_rest" value="1" class="btn-danger-outline"<?= $stats['scheduled'] + $stats['queued'] === 0 ? ' disabled' : '' ?><?= Ui::confirm('Anulować pozostałe wiadomości?', 'Wiadomości, które Gammu właśnie wysyła, nie zostaną zatrzymane.', 'Anuluj pozostałe') ?>><?= icon('close-circle') ?>Anuluj pozostałe (<?= $stats['scheduled'] + $stats['queued'] ?>)</button>
</form></header>
<?= flashes_html() ?>
<?= view('batch-progress', ['id' => $id, 'meta' => $meta, 'stats' => $stats]) ?>
<div class="gap-lg"></div>
<section class="card flush">
<header><h2>Odbiorcy</h2><a href="<?= e(url('sent', ['batch' => $id])) ?>"><strong>Pokaż w wysłanych</strong></a></header>
<div class="table-wrap"><table>
<thead><tr><th scope="col">Odbiorca</th><th scope="col">Status</th><th scope="col">Modem</th></tr></thead>
<tbody>
<?php foreach ($rows as $m): ?>
<tr><td><?= Ui::who($m['phone']) ?></td><td><?= Ui::status($m) ?></td><td><?= e($m['modem'] ?? '—') ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
</section>
