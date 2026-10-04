<?php
/** @var string $content @var string $title @var string|null $nav */
$nav ??= '';
$status = Status::indicator();
$rest = flashes_html();
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false,"allowScriptTags":false,"historyCacheSize":0}'>
<title><?= e($title ?? t('app.name')) ?> – <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/pico/pico.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script type="application/json" id="i18n"><?= Ui::jsTexts() ?></script>
<script src="<?= e(asset('vendor/htmx/htmx.min.js')) ?>" defer></script>
<script src="<?= e(asset('sms-text.js')) ?>" defer></script>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body hx-headers='<?= e(json_encode(['X-CSRF-Token' => csrf_token()])) ?>'>
<div class="app">
<header class="topbar">
<button type="button" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="<?= e(t('layout.menu')) ?>"><?= icon('hamburger-menu', 'icon-lg') ?></button>
<strong><?= e($title ?? '') ?></strong><span class="dot dot-<?= e($status['level']) ?>"></span><span class="muted small"><?= e($status['modem']) ?></span>
</header>
<nav class="sidebar" id="sidebar" aria-label="<?= e(t('layout.main_menu')) ?>" hx-get="<?= e(url('nav', ['cur' => $nav])) ?>" hx-trigger="every 15s" hx-swap="innerHTML">
<?= view('partials/nav', ['nav' => $nav, 'status' => $status]) ?>
</nav>
<main class="main" id="main">
<?= $rest ?>
<?= $content ?>
</main>
</div>
<?= $dialogs ?? '' ?>
<dialog id="confirm-dialog" aria-labelledby="confirm-dialog-t">
<article class="dlg-sm">
<div class="dlg-head"><span class="dlg-icon"><?= icon('danger-triangle', 'icon-lg') ?></span>
<div><h2 id="confirm-dialog-t"></h2><p data-confirm-text></p></div></div>
<footer>
<button type="button" class="secondary outline" data-dialog-close><?= e(t('common.cancel')) ?></button>
<button type="button" class="btn-danger" data-confirm-ok><?= e(t('common.confirm')) ?></button>
</footer>
</article>
</dialog>
</body>
</html>
