<?php /** @var string $editorText @var array $validation @var string $path */ ?>
<form method="post" action="<?= e(url('config', ['tab' => 'editor'])) ?>" id="conf-form" class="split"><?= csrf_field() ?>
<section class="card">
<header><h2><?= e($path) ?></h2><span class="muted"><?= substr_count(rtrim($editorText, "\n"), "\n") + 1 ?> linii · UTF-8</span></header>
<label for="conf-text" class="visually-hidden">Treść pliku gammu-smsdrc</label>
<textarea id="conf-text" name="conf" class="code-editor" spellcheck="false" rows="24"><?= e($editorText) ?></textarea>
<p class="row muted small gap-lg"><?= icon('lock-password') ?>Hasło do bazy i PIN są maskowane – niezmienione pole <code><?= GammuConf::MASK ?></code> przy zapisie zachowuje oryginalną wartość.</p>
</section>
<section class="card">
<header><h2>Walidacja</h2></header>
<ul class="health">
<?php foreach ($validation as [$lvl, $txt]): ?><li class="<?= e($lvl) ?>"><?= icon(['ok' => 'check-circle', 'warn' => 'danger-triangle', 'err' => 'danger-circle'][$lvl]) ?><span><?= e($txt) ?></span></li><?php endforeach ?>
</ul>
<div class="gap-lg row"><button type="submit" name="action" value="validate" class="secondary outline btn-sm"><?= icon('refresh') ?>Sprawdź ponownie</button>
<button type="submit" name="action" value="editor" class="btn-sm"><?= icon('diskette') ?>Zapisz zmiany</button></div>
</section>
</form>
