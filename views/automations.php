<?php /** @var array $channels @var int $id @var array $form @var bool $hasSecret @var ?string $error @var ?string $mailError @var array $deliveries @var array $mail */ ?>
<header class="page-head"><div><h1><?= e(t('nav.automations')) ?></h1><p class="sub"><?= e(t('channels.sub')) ?></p></div>
<div class="actions"><a href="<?= e(url('automations')) ?>" role="button"><?= icon('add-circle') ?><?= e(t('channels.new')) ?></a></div></header>
<?= flashes_html() ?>
<?php if ($deliveries['failed'] > 0): ?><?= alert('err', tn('channels.failed_n', $deliveries['failed'])) ?><?php endif ?>
<div class="split split-520">
<div class="tpl-list">
<?php if ($channels === []): ?><section class="card"><?= Ui::empty('letter', t('channels.empty'), t('channels.empty_text')) ?></section><?php endif ?>
<?php foreach ($channels as $c): $cc = Channels::config($c); ?>
<article class="tpl"<?= $id === (int) $c['id'] ? ' aria-current="true"' : '' ?>>
<header><strong><?= e($c['name']) ?></strong><span class="badge"><?= e(t('channels.type_' . $c['type'])) ?></span><?= $c['enabled'] ? '' : ' <span class="badge badge-warn">' . e(t('channels.disabled')) . '</span>' ?>
<form class="cell-actions" method="post" action="<?= e(url('automations', ['id' => $c['id']])) ?>"><?= csrf_field() ?>
<?= Ui::iconBtnLink(url('automations', ['id' => $c['id']]), 'pen', t('common.edit')) ?>
<button type="submit" name="test" value="1" class="icon-btn" aria-label="<?= e(t('channels.test')) ?>" title="<?= e(t('channels.test')) ?>"><?= icon('play') ?></button>
<button type="submit" name="toggle" value="<?= $c['enabled'] ? '0' : '1' ?>" class="icon-btn" aria-label="<?= e(t($c['enabled'] ? 'channels.disable' : 'channels.enable')) ?>" title="<?= e(t($c['enabled'] ? 'channels.disable' : 'channels.enable')) ?>"><?= icon($c['enabled'] ? 'forbidden-circle' : 'check-circle') ?></button>
<button type="submit" name="delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"<?= Ui::confirm(t('channels.delete_q', ['name' => $c['name']]), t('channels.delete_text'), t('common.delete')) ?>><?= icon('trash-bin-trash') ?></button></form></header>
<p class="mono"><?= e($c['type'] === 'email' ? ($cc['to'] ?? '') : ($cc['url'] ?? '')) ?></p>
<footer><span class="muted small"><?= $c['last_error'] ? e(t('channels.last_error', ['error' => $c['last_error']])) : ($c['last_ok_at'] ? e(t('channels.last_ok', ['time' => fmt_when($c['last_ok_at'])])) : e(t('channels.never_used'))) ?></span></footer>
</article>
<?php endforeach ?>
</div>
<div class="stack">
<section class="card">
<header><h2><?= e(t($id ? 'channels.edit' : 'channels.new')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e(url('automations', ['id' => $id ?: null])) ?>"><?= csrf_field() ?>
<?php if ($error): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="field"><label for="ch-type"><?= e(t('channels.type')) ?></label>
<select id="ch-type" name="type" data-type-switch<?= $id ? ' disabled' : '' ?>>
<?php foreach (['email', 'webhook'] as $ty): ?><option value="<?= $ty ?>"<?= $form['type'] === $ty ? ' selected' : '' ?>><?= e(t('channels.type_' . $ty)) ?></option><?php endforeach ?></select>
<?php if ($id): ?><input type="hidden" name="type" value="<?= e($form['type']) ?>"><?php endif ?></div>
<div class="field"><label for="ch-name"><?= e(t('channels.name')) ?></label><input id="ch-name" name="name" type="text" value="<?= e($form['name']) ?>" required></div>
<div class="field" data-show-for="email"<?= $form['type'] === 'email' ? '' : ' hidden' ?>><label for="ch-to"><?= e(t('channels.to')) ?></label><input id="ch-to" name="to" type="text" value="<?= e($form['to']) ?>" placeholder="ktos@example.com"><small><?= e(t('channels.to_hint')) ?></small></div>
<div class="field" data-show-for="webhook"<?= $form['type'] === 'webhook' ? '' : ' hidden' ?>><label for="ch-url"><?= e(t('channels.url')) ?></label><input id="ch-url" name="url" type="url" value="<?= e($form['url']) ?>" placeholder="https://"><small><?= e(t('channels.url_hint')) ?></small></div>
<div class="field" data-show-for="webhook"<?= $form['type'] === 'webhook' ? '' : ' hidden' ?>><label for="ch-secret"><?= e(t('channels.secret')) ?></label><input id="ch-secret" name="secret" type="password" autocomplete="new-password" placeholder="<?= e($hasSecret ? t('channels.secret_kept') : '') ?>"><small><?= e(t('channels.secret_hint')) ?></small>
<?php if ($hasSecret): ?><label><input type="checkbox" name="secret_clear" value="1"> <span><?= e(t('channels.secret_clear')) ?></span></label><?php endif ?></div>
<div class="row"><button type="submit"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button><a href="<?= e(url('automations')) ?>" role="button" class="secondary outline"><?= e(t('common.cancel')) ?></a></div>
</form>
</section>
<section class="card">
<header><h2><?= e(t('channels.mail')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e(url('automations')) ?>"><?= csrf_field() ?>
<?php if ($mailError): ?><?= alert('err', '', $mailError) ?><?php endif ?>
<div class="field"><label for="m-mode"><?= e(t('channels.mail_mode')) ?></label>
<select id="m-mode" name="mode" data-type-switch>
<option value="sendmail"<?= $mail['mode'] === 'sendmail' ? ' selected' : '' ?>><?= e(t('channels.mode_sendmail')) ?></option>
<option value="smtp"<?= $mail['mode'] === 'smtp' ? ' selected' : '' ?>><?= e(t('channels.mode_smtp')) ?></option></select>
<small data-show-for="sendmail"<?= $mail['mode'] === 'sendmail' ? '' : ' hidden' ?>><?= e(t('channels.sendmail_hint')) ?></small></div>
<div data-show-for="smtp"<?= $mail['mode'] === 'smtp' ? '' : ' hidden' ?> class="stack-sm">
<div class="grid-2">
<div class="field"><label for="m-host"><?= e(t('channels.smtp_host')) ?></label><input id="m-host" name="host" type="text" value="<?= e($mail['host']) ?>"></div>
<div class="field"><label for="m-port"><?= e(t('channels.smtp_port')) ?></label><input id="m-port" name="port" type="number" min="1" max="65535" value="<?= e($mail['port']) ?>"></div></div>
<div class="field"><label for="m-secure"><?= e(t('channels.smtp_secure')) ?></label><select id="m-secure" name="secure">
<?php foreach (['tls' => 'channels.secure_tls', 'ssl' => 'channels.secure_ssl', '' => 'channels.secure_none'] as $k => $key): ?><option value="<?= $k ?>"<?= $mail['secure'] === (string) $k ? ' selected' : '' ?>><?= e(t($key)) ?></option><?php endforeach ?></select></div>
<div class="grid-2">
<div class="field"><label for="m-user"><?= e(t('channels.smtp_user')) ?></label><input id="m-user" name="user" type="text" autocomplete="off" value="<?= e($mail['user']) ?>"></div>
<div class="field"><label for="m-pass"><?= e(t('channels.smtp_pass')) ?></label><input id="m-pass" name="pass" type="password" autocomplete="new-password" placeholder="<?= e(Settings::json('mail')['pass'] ?? '' ? t('channels.secret_kept') : '') ?>"></div></div>
<?php if (Settings::json('mail')['pass'] ?? ''): ?><label><input type="checkbox" name="pass_clear" value="1"> <span><?= e(t('channels.secret_clear')) ?></span></label><?php endif ?>
</div>
<div class="grid-2">
<div class="field"><label for="m-from"><?= e(t('channels.from')) ?></label><input id="m-from" name="from" type="email" value="<?= e($mail['from']) ?>"></div>
<div class="field"><label for="m-fromname"><?= e(t('channels.from_name')) ?></label><input id="m-fromname" name="from_name" type="text" value="<?= e($mail['from_name']) ?>"></div></div>
<div class="row"><button type="submit" name="mail_save" value="1"><?= icon('diskette') ?><?= e(t('channels.save')) ?></button></div>
</form>
</section>
</div>
</div>
