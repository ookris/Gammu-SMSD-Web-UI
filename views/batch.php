<?php /** @var string $id @var array $meta @var array $stats @var array $rows */ ?>
<header class="page-head"><div><h1><?= e(t('batch.title')) ?></h1><p class="sub"><?= e($meta['label'] ?: tn('sent.recipients', (int) $stats['total'])) ?> · <?= e(fmt_when($meta['created'])) ?> · <a href="<?= e(url('sent')) ?>"><?= e(t('batch.back')) ?></a></p></div>
<form class="actions" method="post" action="<?= e(url('batch', ['id' => $id])) ?>"><?= csrf_field() ?>
<button type="submit" name="retry_failed" value="1" class="secondary"<?= $stats['failed'] === 0 ? ' disabled' : '' ?>><?= icon('restart') ?><?= e(t('batch.retry_failed', ['n' => $stats['failed']])) ?></button>
<button type="submit" name="cancel_rest" value="1" class="btn-danger-outline"<?= $stats['scheduled'] + $stats['queued'] === 0 ? ' disabled' : '' ?><?= Ui::confirm(t('batch.cancel_rest_q'), t('sent.cancel_selected_text'), t('batch.cancel_rest_ok')) ?>><?= icon('close-circle') ?><?= e(t('batch.cancel_rest', ['n' => $stats['scheduled'] + $stats['queued']])) ?></button>
</form></header>
<?= flashes_html() ?>
<?= view('batch-progress', ['id' => $id, 'meta' => $meta, 'stats' => $stats]) ?>
<div class="gap-lg"></div>
<section class="card flush">
<header><h2><?= e(t('batch.recipients')) ?></h2><a href="<?= e(url('sent', ['batch' => $id])) ?>"><strong><?= e(t('batch.show_in_sent')) ?></strong></a></header>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('common.recipient')) ?></th><th scope="col"><?= e(t('common.status')) ?></th><th scope="col"><?= e(t('inbox.modem')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $m): ?>
<tr><td><?= Ui::who($m['phone']) ?></td><td><?= Ui::status($m) ?></td><td><?= e($m['modem'] ?? '—') ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
</section>
