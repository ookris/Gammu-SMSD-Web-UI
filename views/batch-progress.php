<?php /** @var string $id @var array $meta @var array $stats */
$running = $stats['scheduled'] + $stats['queued'] > 0;
$end = $meta['last_at'] ? date('H:i', (int) ts($meta['last_at'])) : null;
?>
<section class="card" id="batch-progress"<?= $running ? ' hx-get="' . e(url('batch', ['id' => $id, 'fragment' => 'progress'])) . '" hx-trigger="every 5s" hx-swap="outerHTML"' : '' ?>>
<header><h2><?= e(t('batch.progress')) ?></h2><span class="muted"><?= e(t($running ? 'batch.refreshing' : 'batch.finished')) ?></span></header>
<div class="progress-row"><progress value="<?= $stats['done'] ?>" max="<?= max(1, $stats['total']) ?>" aria-label="<?= e(t('batch.progress_label')) ?>"></progress><strong><?= e(t('batch.done_of', ['done' => $stats['done'], 'total' => $stats['total']])) ?></strong></div>
<div class="stat-row"><span class="badge badge-ok"><?= e(t('sent.n_delivered', ['n' => $stats['delivered']])) ?></span><span class="badge badge-info"><?= e(t('sent.n_sent', ['n' => $stats['sent']])) ?></span><span class="badge badge-warn"><?= e(t('sent.n_queued', ['n' => $stats['queued']])) ?></span><span class="badge"><?= e(t('sent.n_scheduled', ['n' => $stats['scheduled']])) ?></span><span class="badge badge-err"><?= e(t('sent.n_failed', ['n' => $stats['failed']])) ?></span><?php if ($stats['cancelled']): ?><span class="badge badge-neutral"><?= e(t('batch.n_cancelled', ['n' => $stats['cancelled']])) ?></span><?php endif ?></div>
<dl class="kv kv-170 gap-lg">
<dt><?= e(t('common.body')) ?></dt><dd><?= nl2br(e($meta['text'])) ?></dd>
<dt><?= e(t('batch.sent_at')) ?></dt><dd><?= e(t('batch.sent_line', ['when' => fmt_when($meta['created']), 'recipients' => tn('sent.recipients', (int) $stats['total']), 'parts' => (int) $meta['parts']])) ?></dd>
<dt><?= e(t('batch.pace')) ?></dt><dd><?= e($meta['per_min'] ? t('batch.per_min', ['n' => (int) $meta['per_min']]) . ($end ? ' · ' . t('batch.end_at', ['time' => $end]) : '') : t('batch.no_throttle')) ?></dd>
<dt><?= e(t('batch.report')) ?></dt><dd><?= e(t($meta['report'] ? 'common.yes' : 'common.no')) ?></dd>
</dl>
</section>
