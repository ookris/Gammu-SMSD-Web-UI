<?php /** @var array $backups @var ?array $shown */ $shown ??= null; ?>
<?php if ($shown !== null): ?>
<section class="card">
<header><h2><?= e(t($shown['mode'] === 'view' ? 'config.preview' : 'config.compare')) ?>: <span class="mono"><?= e($shown['name']) ?></span></h2><a href="<?= e(url('config', ['tab' => 'backups'])) ?>"><strong><?= e(t('config.close')) ?></strong></a></header>
<?php if ($shown['mode'] === 'view'): ?><pre class="codebox numbered tall"><?php foreach (explode("\n", rtrim($shown['text'], "\n")) as $l): ?><span><?= e($l) ?></span><?php endforeach ?></pre>
<?php elseif (Diff::changed($shown['diff']) === 0): ?><p class="status-line"><?= icon('check-circle') ?><?= e(t('config.identical')) ?></p>
<?php else: ?><p class="muted small"><?= e(t('config.diff_legend')) ?></p><pre class="codebox"><?= Diff::html($shown['diff']) ?></pre><?php endif ?>
</section>
<div class="gap-lg"></div>
<?php endif ?>
<section class="card flush">
<header><h2><?= e(t('config.tab_backups', ['n' => count($backups)])) ?></h2></header>
<?php if ($backups === []): ?><?= Ui::empty('history', t('config.backups_empty'), t('config.backups_empty_text')) ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('config.col_backup')) ?></th><th scope="col"><?= e(t('config.col_size')) ?></th><th scope="col"><?= e(t('config.col_change')) ?></th><th scope="col"><?= e(t('common.actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $b): ?>
<tr><td><span class="mono"><?= e($b['name']) ?></span><span class="sub"><?= e(fmt_date($b['time'], 'H:i')) ?></span></td><td><?= e(fmt_bytes($b['size'])) ?></td><td><?= e(tr($b['note']) ?: '—') ?></td>
<td><form class="cell-actions" method="post" action="<?= e(url('config', ['tab' => 'backups'])) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><input type="hidden" name="base" value="<?= e($base) ?>">
<a href="<?= e(url('config', ['tab' => 'backups', 'view' => $b['name']])) ?>" role="button" class="secondary outline btn-sm"><?= e(t('config.preview')) ?></a>
<a href="<?= e(url('config', ['tab' => 'backups', 'compare' => $b['name']])) ?>" role="button" class="secondary outline btn-sm"><?= e(t('config.compare_btn')) ?></a>
<button type="submit" name="action" value="restore" class="btn-warn btn-sm"><?= e(t('config.restore')) ?></button></form></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="card-note"><?= t('config.backups_note', ['dir' => '<code>' . e(GammuConf::backupDir()) . '/</code>', 'n' => Settings::int('backup_keep')]) ?></p>
</section>
