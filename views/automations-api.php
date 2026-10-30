<?php /** @var array $tokens @var ?string $newToken */
$base = (Auth::https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'host');
$apiUrl = static fn (string $path) => $base . '/api/' . $path;
?>
<div class="split split-520">
<div class="stack">
<?php if ($newToken !== null): ?>
<section class="card">
<?= alert('ok', t('api.created'), t('api.created_text')) ?>
<div class="field"><label for="new-token"><?= e(t('api.token')) ?></label>
<div class="row"><input id="new-token" type="text" readonly value="<?= e($newToken) ?>" class="mono-input grow-1" data-select-on-focus>
<button type="button" class="secondary outline btn-sm copy-code" data-copy="<?= e($newToken) ?>" data-copied="<?= e(t('inbox.copied')) ?>"><?= icon('copy') ?><span><?= e(t('api.copy')) ?></span></button></div></div>
</section>
<?php endif ?>
<div class="tpl-list">
<?php if ($tokens === []): ?><section class="card"><?= Ui::empty('lock-password', t('api.empty'), t('api.empty_text')) ?></section><?php endif ?>
<?php foreach ($tokens as $tk): ?>
<article class="tpl">
<header><strong><?= e($tk['name']) ?></strong><span class="badge mono"><?= e($tk['token_prefix']) ?>…</span><?= $tk['revoked_at'] ? ' <span class="badge badge-err">' . e(t('api.revoked_badge')) . '</span>' : '' ?>
<form class="cell-actions" method="post" action="<?= e(url('automations', ['tab' => 'api', 'token' => $tk['id']])) ?>"><?= csrf_field() ?>
<?php if ($tk['revoked_at']): ?>
<button type="submit" name="token_delete" value="1" class="icon-btn danger" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"><?= icon('trash-bin-trash') ?></button>
<?php else: ?>
<button type="submit" name="token_revoke" value="1" class="icon-btn danger" aria-label="<?= e(t('api.revoke')) ?>" title="<?= e(t('api.revoke')) ?>"<?= Ui::confirm(t('api.revoke_q', ['name' => $tk['name']]), t('api.revoke_text'), t('api.revoke')) ?>><?= icon('forbidden-circle') ?></button>
<?php endif ?></form></header>
<p class="muted small"><?= e($tk['allowed_ips'] ? t('api.ips', ['ips' => $tk['allowed_ips']]) : t('api.any_ip')) ?></p>
<footer><span class="muted small"><?= e(t('api.created_at', ['time' => fmt_when($tk['created_at'])])) ?> · <?= $tk['last_used_at'] ? e(t('api.last_used', ['time' => fmt_when($tk['last_used_at']), 'ip' => $tk['last_ip']])) : e(t('channels.never_used')) ?></span></footer>
</article>
<?php endforeach ?>
</div>
</div>
<div class="stack">
<section class="card">
<header><h2><?= e(t('api.new')) ?></h2></header>
<form class="stack-sm" method="post" action="<?= e(url('automations', ['tab' => 'api'])) ?>"><?= csrf_field() ?>
<div class="field"><label for="tk-name"><?= e(t('api.name')) ?></label><input id="tk-name" name="name" type="text" required placeholder="<?= e(t('api.name_ph')) ?>"></div>
<div class="field"><label for="tk-ips"><?= e(t('api.allowed')) ?></label><input id="tk-ips" name="ips" type="text" placeholder="192.168.1.0/24, 10.0.0.5"><small><?= e(t('api.allowed_hint')) ?></small></div>
<div class="row"><button type="submit"><?= icon('add-circle') ?><?= e(t('api.create')) ?></button></div>
</form>
</section>
<section class="card">
<header><h2><?= e(t('api.docs')) ?></h2></header>
<p class="muted small"><?= e(t('api.docs_text')) ?></p>
<pre class="code-block"><code>curl -X POST <?= e($apiUrl('send')) ?> \
  -H "Authorization: Bearer sg_…" \
  -d to=601234567 -d "text=Test z API"</code></pre>
<pre class="code-block"><code>curl -H "Authorization: Bearer sg_…" \
  "<?= e($apiUrl('status')) ?>?id=123"</code></pre>
<p class="muted small"><?= e(t('api.params')) ?></p>
</section>
</div>
</div>
