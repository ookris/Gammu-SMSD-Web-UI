<?php /** @var array $channels @var array $routes @var array $alertState */ ?>
<div class="stack">
<section class="card">
<header><h2><?= e(t('alerts.title')) ?></h2></header>
<p class="muted small"><?= e(t('alerts.hint')) ?></p>
<?php if ($channels === []): ?><p class="muted"><?= e(t('alerts.no_channels')) ?></p><?php else: ?>
<form method="post" action="<?= e(url('automations', ['tab' => 'alerts'])) ?>"><?= csrf_field() ?>
<div class="table-wrap"><table>
<thead><tr><th scope="col"><?= e(t('alerts.event')) ?></th><?php foreach ($channels as $c): ?><th scope="col"><?= e($c['name']) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach (Alerts::TYPES as $type): ?>
<tr><th scope="row"><?= e(t("alerts.$type.title")) ?><?= !empty($alertState[$type]['since']) ? ' <span class="badge badge-warn">' . e(t('alerts.active')) . '</span>' : '' ?></th>
<?php foreach ($channels as $c): ?><td><input type="checkbox" name="route[<?= e($type) ?>][]" value="<?= (int) $c['id'] ?>" aria-label="<?= e(t("alerts.$type.title") . ' → ' . $c['name']) ?>"<?= in_array((int) $c['id'], $routes[$type] ?? [], true) ? ' checked' : '' ?>></td><?php endforeach ?></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="muted small"><?= e(t('alerts.sms_note')) ?></p>
<div class="row"><button type="submit" name="alerts_save" value="1"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button></div>
</form>
<?php endif ?>
</section>
</div>
