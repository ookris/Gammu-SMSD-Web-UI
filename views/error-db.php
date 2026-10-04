<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Brak połączenia z bazą – <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/pico/pico.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<main class="solo">
<div class="solo-box solo-wide">
<div class="card stack-sm"><?= icon('database', 'icon-xl') ?>
<h1>Brak połączenia z bazą danych</h1>
<p class="muted">Panel nie może połączyć się z MariaDB. Sprawdź, czy usługa działa: <code>systemctl status mariadb</code>, a potem uruchom diagnostykę: <code>sudo -u www-data php /opt/smsgui/bin/smsgui check</code>.</p>
<?php if (isset($error) && $error instanceof Throwable): ?><pre class="codebox"><span><?= e($error->getMessage()) ?></span></pre><?php endif ?>
<div><a href="./" role="button" class="secondary"><?= icon('refresh') ?>Spróbuj ponownie</a></div>
</div>
</div>
</main>
</body>
</html>
