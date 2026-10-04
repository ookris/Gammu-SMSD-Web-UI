<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e(t('error.db_title')) ?> – <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/pico/pico.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<main class="solo">
<div class="solo-box solo-wide">
<div class="card stack-sm"><?= icon('database', 'icon-xl') ?>
<h1><?= e(t('error.db_heading')) ?></h1>
<p class="muted"><?= t('error.db_text', ['status' => '<code>systemctl status mariadb</code>', 'check' => '<code>sudo -u www-data php /opt/smsgui/bin/smsgui check</code>']) ?></p>
<?php if (isset($error) && $error instanceof Throwable): ?><pre class="codebox"><span><?= e($error->getMessage()) ?></span></pre><?php endif ?>
<div><a href="./" role="button" class="secondary"><?= icon('refresh') ?><?= e(t('error.retry')) ?></a></div>
</div>
</div>
</main>
</body>
</html>
