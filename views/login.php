<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Logowanie – <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/pico/pico.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<main class="solo">
<div class="solo-box">
<div class="brand"><span class="brand-mark"><?= icon('chat-round-line') ?></span><span class="brand-name"><?= e(t('app.name')) ?></span></div>
<form class="card" method="post" action="<?= e(url('login')) ?>">
<?= csrf_field() ?>
<input type="hidden" name="next" value="<?= e($next) ?>">
<h1>Zaloguj się</h1>
<?php foreach ($flashes as $f): ?><?= alert($f['type'], '', $f['title']) ?><?php endforeach ?>
<?php if ($error !== null): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="field">
<label for="username">Login</label>
<input id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required autofocus>
</div>
<div class="field">
<label for="password">Hasło</label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
</div>
<label><input type="checkbox" name="remember" value="1"> <span>Zapamiętaj mnie na 30 dni</span></label>
<button type="submit">Zaloguj</button>
</form>
<p>Gammu SMSD · dostęp tylko z sieci lokalnej</p>
</div>
</main>
</body>
</html>
