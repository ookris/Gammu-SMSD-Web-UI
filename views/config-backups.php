<?php /** @var array $backups @var ?array $shown */ $shown ??= null; ?>
<?php if ($shown !== null): ?>
<section class="card">
<header><h2><?= $shown['mode'] === 'view' ? 'Podgląd' : 'Porównanie z bieżącą' ?>: <span class="mono"><?= e($shown['name']) ?></span></h2><a href="<?= e(url('config', ['tab' => 'backups'])) ?>"><strong>Zamknij</strong></a></header>
<?php if ($shown['mode'] === 'view'): ?><pre class="codebox numbered tall"><?php foreach (explode("\n", rtrim($shown['text'], "\n")) as $l): ?><span><?= e($l) ?></span><?php endforeach ?></pre>
<?php elseif (Diff::changed($shown['diff']) === 0): ?><p class="status-line"><?= icon('check-circle') ?>Kopia jest identyczna z bieżącym plikiem.</p>
<?php else: ?><p class="muted small">„−” – tylko w kopii, „+” – tylko w bieżącym pliku.</p><pre class="codebox"><?= Diff::html($shown['diff']) ?></pre><?php endif ?>
</section>
<div class="gap-lg"></div>
<?php endif ?>
<section class="card flush">
<header><h2>Kopie zapasowe (<?= count($backups) ?>)</h2></header>
<?php if ($backups === []): ?><?= Ui::empty('history', 'Nie ma jeszcze kopii', 'Kopia powstaje automatycznie przed każdym zapisem pliku.') ?><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col">Kopia</th><th scope="col">Rozmiar</th><th scope="col">Zmiana, która nastąpiła po kopii</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($backups as $b): ?>
<tr><td><span class="mono"><?= e($b['name']) ?></span><span class="sub"><?= e(date('d.m.Y H:i', $b['time'])) ?></span></td><td><?= e(fmt_bytes($b['size'])) ?></td><td><?= e($b['note'] ?: '—') ?></td>
<td><form class="cell-actions" method="post" action="<?= e(url('config', ['tab' => 'backups'])) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><input type="hidden" name="base" value="<?= e($base) ?>">
<a href="<?= e(url('config', ['tab' => 'backups', 'view' => $b['name']])) ?>" role="button" class="secondary outline btn-sm">Podgląd</a>
<a href="<?= e(url('config', ['tab' => 'backups', 'compare' => $b['name']])) ?>" role="button" class="secondary outline btn-sm">Porównaj z bieżącą</a>
<button type="submit" name="action" value="restore" class="btn-warn btn-sm">Przywróć</button></form></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="card-note">Kopie w <code><?= e(GammuConf::backupDir()) ?>/</code> · trzymane jest ostatnie <?= Settings::int('backup_keep') ?> · przywrócenie też przechodzi przez okno potwierdzenia.</p>
</section>
