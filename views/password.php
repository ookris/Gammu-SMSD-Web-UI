<header class="page-head"><div><h1><?= e(t('password.title')) ?></h1><p class="sub"><?= e(t('password.sub')) ?></p></div></header>
<?= flashes_html() ?>
<section class="card narrow">
<form class="stack-sm" method="post" action="<?= e(url('password')) ?>">
<?= csrf_field() ?>
<?php if ($error !== null): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="field">
<label for="username"><?= e(t('login.username')) ?></label>
<input id="username" aria-describedby="username-h" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required><small id="username-h"><?= e(t('password.username_hint')) ?></small>
</div>
<div class="field">
<label for="current"><?= e(t('password.current')) ?></label>
<input id="current" name="current" type="password" autocomplete="current-password" required>
</div>
<div class="field">
<label for="new"><?= e(t('password.new')) ?></label>
<input id="new" aria-describedby="new-h" name="new" type="password" autocomplete="new-password" minlength="10" required><small id="new-h"><?= e(t('password.new_hint')) ?></small>
</div>
<div class="field">
<label for="repeat"><?= e(t('password.repeat')) ?></label>
<input id="repeat" name="repeat" type="password" autocomplete="new-password" required>
</div>
<div><button type="submit"><?= icon('lock-password') ?><?= e(t('password.submit')) ?></button></div>
</form>
</section>
<p class="muted small gap-lg narrow"><?= t('password.forgot', ['cmd' => '<code>sudo -u www-data php /opt/smsgui/bin/smsgui passwd</code>']) ?></p>
