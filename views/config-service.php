<?php /** @var string $statusText @var bool $active @var ?array $op @var bool $wait @var array $logLines */ ?>
<div class="stack">
<section class="card">
<header><h2>Usługa</h2></header>
<p class="row"><span class="dot dot-<?= $active ? 'ok' : 'err' ?>"></span><strong><?= e(cfg('service.name', 'gammu-smsd')) ?>: <?= e($statusText !== '' ? strtok($statusText, "\n") : 'nieznany stan') ?></strong></p>
<form class="row" method="post" action="<?= e(url('config', ['tab' => 'service'])) ?>"><?= csrf_field() ?>
<button type="submit" name="action" value="reload" class="secondary"><?= icon('refresh') ?>Przeładuj konfigurację</button>
<button type="submit" name="action" value="restart" class="btn-danger-outline"<?= Ui::confirm('Zrestartować Gammu SMSD?', 'Na kilka sekund wysyłka i odbiór zostaną wstrzymane. Wiadomości w kolejce poczekają. Panel poczeka do 30 s, aż modem zgłosi się ponownie.', 'Restartuj') ?>><?= icon('restart') ?>Restartuj Gammu</button>
</form>
<p class="muted small gap-lg">Przeładowanie (SIGHUP) wczytuje ponownie konfigurację i czarną listę – Gammu kończy bieżącą pętlę i łączy się z modemem od nowa. Restart zatrzymuje i uruchamia usługę.</p>
</section>
<?php if ($op !== null): ?>
<section class="card">
<header><h2>Wynik ostatniej operacji</h2><span class="muted"><?= e(fmt_when($op['started'])) ?></span></header>
<div class="stack-sm">
<p class="status-line"><?= icon($op['code'] === 0 ? 'check-circle' : 'danger-circle') ?>Polecenie <code><?= e($op['cmd']) ?></code> zakończone (kod <?= (int) $op['code'] ?>)</p>
<?php if ($op['out'] !== ''): ?><pre class="codebox"><span><?= e($op['out']) ?></span></pre><?php endif ?>
<?php if (($op['reason'] ?? '') !== 'przeładowanie'): ?><?= view('config-modemwait', ['op' => $op, 'wait' => $wait]) ?><?php endif ?>
</div>
</section>
<?php endif ?>
<section class="card">
<header><h2>Ostatnie linie logu</h2><a href="<?= e(url('log')) ?>"><strong>Cały log</strong></a></header>
<?php if ($logLines === []): ?><p class="muted">Log Gammu jest pusty albo nieczytelny dla panelu.</p><?php else: ?>
<pre class="codebox"><?php foreach ($logLines as $l): ?><span<?= preg_match('/error|failed/i', $l) ? ' class="err"' : '' ?>><?= e($l) ?></span><?php endforeach ?></pre><?php endif ?>
</section>
</div>
