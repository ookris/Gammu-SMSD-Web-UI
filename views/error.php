<?php
$code ??= 500;
$title ??= t(in_array($code, [403, 404], true) ? 'error.' . $code : 'error.500');
$message ??= t($code === 404 ? 'error.404_text' : 'error.500_text');
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> – <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/pico/pico.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<main class="solo">
<div class="solo-box<?= isset($error) ? ' solo-wide' : '' ?>">
<div class="card stack-sm"><span class="big-code"><?= e($code) ?></span>
<h1><?= e($title) ?></h1>
<p class="muted"><?= e($message) ?></p>
<?php if (isset($error) && $error instanceof Throwable): ?><pre class="codebox"><span><?= e($error::class . ': ' . $error->getMessage()) ?></span><span><?= e($error->getFile() . ':' . $error->getLine()) ?></span></pre><?php endif ?>
<div><a href="./" role="button" class="secondary"><?= e(t('error.back')) ?></a></div>
</div>
</div>
</main>
</body>
</html>
