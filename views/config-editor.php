<?php /** @var string $editorText @var array $validation @var string $path */ ?>
<form method="post" action="<?= e(url('config', ['tab' => 'editor'])) ?>" id="conf-form" class="split"><?= csrf_field() ?><input type="hidden" name="base" value="<?= e($base) ?>">
<section class="card">
<header><h2><?= e($path) ?></h2><span class="muted"><?= e(t('config.lines', ['n' => substr_count(rtrim($editorText, "\n"), "\n") + 1])) ?></span></header>
<label for="conf-text" class="visually-hidden"><?= e(t('config.editor_label')) ?></label>
<textarea id="conf-text" name="conf" class="code-editor" spellcheck="false" rows="24"><?= e($editorText) ?></textarea>
<p class="row muted small gap-lg"><?= icon('lock-password') ?><?= t('config.mask_note', ['mask' => '<code>' . e(GammuConf::MASK) . '</code>']) ?></p>
</section>
<section class="card">
<header><h2><?= e(t('config.validation')) ?></h2></header>
<ul class="health">
<?php foreach ($validation as [$lvl, $txt]): ?><li class="<?= e($lvl) ?>"><?= icon(['ok' => 'check-circle', 'warn' => 'danger-triangle', 'err' => 'danger-circle'][$lvl]) ?><span><?= e($txt) ?></span></li><?php endforeach ?>
</ul>
<div class="gap-lg row"><button type="submit" name="action" value="validate" class="secondary outline btn-sm"><?= icon('refresh') ?><?= e(t('config.validate_again')) ?></button>
<button type="submit" name="action" value="editor" class="btn-sm"><?= icon('diskette') ?><?= e(t('config.save')) ?></button></div>
</section>
</form>
