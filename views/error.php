<?php
$code ??= 500;
$title ??= match ($code) { 404 => 'Nie ma takiej strony', 403 => 'Odmowa dostępu', default => 'Wystąpił błąd' };
$message ??= match ($code) {
    404 => 'Adres jest nieprawidłowy albo strona została usunięta.',
    default => 'Szczegóły zapisano w logu aplikacji. Spróbuj ponownie za chwilę.',
};
?>
<!doctype html>
<html lang="pl">
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
<div><a href="./" role="button" class="secondary">Wróć na pulpit</a></div>
</div>
</div>
</main>
</body>
</html>
