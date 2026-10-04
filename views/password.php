<header class="page-head"><div><h1>Zmiana hasła</h1><p class="sub">Konto administratora panelu</p></div></header>
<?= flashes_html() ?>
<section class="card narrow">
<form class="stack-sm" method="post" action="<?= e(url('password')) ?>">
<?= csrf_field() ?>
<?php if ($error !== null): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="field">
<label for="username">Login</label>
<input id="username" aria-describedby="username-h" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required><small id="username-h">Możesz go zmienić w tym samym formularzu</small>
</div>
<div class="field">
<label for="current">Obecne hasło</label>
<input id="current" name="current" type="password" autocomplete="current-password" required>
</div>
<div class="field">
<label for="new">Nowe hasło</label>
<input id="new" aria-describedby="new-h" name="new" type="password" autocomplete="new-password" minlength="10" required><small id="new-h">Co najmniej 10 znaków</small>
</div>
<div class="field">
<label for="repeat">Powtórz nowe hasło</label>
<input id="repeat" name="repeat" type="password" autocomplete="new-password" required>
</div>
<div><button type="submit"><?= icon('lock-password') ?>Zmień hasło</button></div>
</form>
</section>
<p class="muted small gap-lg narrow">Zapomniane hasło ustawisz z konsoli serwera: <code>sudo -u www-data php /opt/smsgui/bin/smsgui passwd</code></p>
