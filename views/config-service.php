<?php /** @var string $statusText @var bool $active @var ?array $op @var bool $wait @var array $logLines */ ?>
<div class="stack">
<section class="card">
<header><h2><?= e(t('config.service')) ?></h2></header>
<p class="row"><span class="dot dot-<?= $active ? 'ok' : 'err' ?>"></span><strong><?= e(cfg('service.name', 'gammu-smsd')) ?>: <?= e($statusText !== '' ? strtok($statusText, "\n") : t('config.unknown_state')) ?></strong></p>
<form class="row" method="post" action="<?= e(url('config', ['tab' => 'service'])) ?>"><?= csrf_field() ?>
<button type="submit" name="action" value="reload" class="secondary"><?= icon('refresh') ?><?= e(t('config.reload')) ?></button>
<button type="submit" name="action" value="restart" class="btn-danger-outline"<?= Ui::confirm(t('config.restart_q'), t('config.restart_text'), t('config.restart_ok')) ?>><?= icon('restart') ?><?= e(t('config.restart')) ?></button>
</form>
<p class="muted small gap-lg"><?= e(t('config.service_note')) ?></p>
</section>
<?php if ($op !== null): ?>
<section class="card">
<header><h2><?= e(t('config.last_op')) ?></h2><span class="muted"><?= e(fmt_when($op['started'])) ?></span></header>
<div class="stack-sm">
<p class="status-line"><?= icon($op['code'] === 0 ? 'check-circle' : 'danger-circle') ?><?= t('config.cmd_done', ['cmd' => '<code>' . e($op['cmd']) . '</code>', 'code' => (int) $op['code']]) ?></p>
<?php if ($op['out'] !== ''): ?><pre class="codebox"><span><?= e($op['out']) ?></span></pre><?php endif ?>
<?php if ($op['wait'] ?? false): ?><?= view('config-modemwait', ['op' => $op, 'wait' => $wait]) ?><?php endif ?>
</div>
</section>
<?php endif ?>
<section class="card">
<header><h2><?= e(t('config.log_tail')) ?></h2><a href="<?= e(url('log')) ?>"><strong><?= e(t('config.full_log')) ?></strong></a></header>
<?php if ($logLines === []): ?><p class="muted"><?= e(t('config.log_empty')) ?></p><?php else: ?>
<pre class="codebox"><?php foreach ($logLines as $l): ?><span<?= preg_match('/error|failed/i', $l) ? ' class="err"' : '' ?>><?= e($l) ?></span><?php endforeach ?></pre><?php endif ?>
</section>
</div>
