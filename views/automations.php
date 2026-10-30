<?php /** @var string $tab @var array $deliveries */
$tabNames = ['channels' => t('channels.tab_channels'), 'alerts' => t('channels.tab_alerts'), 'rules' => t('channels.tab_rules'), 'replies' => t('channels.tab_replies'), 'schedules' => t('channels.tab_schedules'), 'api' => t('channels.tab_api')];
?>
<header class="page-head"><div><h1><?= e(t('nav.automations')) ?></h1><p class="sub"><?= e(t('channels.sub')) ?></p></div>
<?php if ($tab === 'channels'): ?><div class="actions"><a href="<?= e(url('automations')) ?>" role="button"><?= icon('add-circle') ?><?= e(t('channels.new')) ?></a></div><?php elseif ($tab === 'schedules'): ?><div class="actions"><a href="<?= e(url('automations', ['tab' => 'schedules'])) ?>" role="button"><?= icon('add-circle') ?><?= e(t('schedules.new')) ?></a></div>
<?php elseif ($tab === 'rules' || $tab === 'replies'): ?><div class="actions"><a href="<?= e(url('automations', ['tab' => $tab])) ?>" role="button"><?= icon('add-circle') ?><?= e(t($tab === 'rules' ? 'rules.new' : 'replies.new')) ?></a></div><?php endif ?></header>
<nav class="tabs" aria-label="<?= e(t('channels.tabs')) ?>"><?php foreach ($tabNames as $k => $label): ?><a href="<?= e(url('automations', ['tab' => $k === 'channels' ? null : $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?></nav>
<?= flashes_html() ?>
<?php if ($deliveries['failed'] > 0): ?><?= alert('err', tn('channels.failed_n', $deliveries['failed'])) ?><?php endif ?>
<?= view('automations-' . $tab, get_defined_vars()) ?>
