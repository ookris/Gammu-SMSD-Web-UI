<?php /** @var string $phone @var bool $oob @var ?string $error @var string $text */ ?>
<form class="reply" id="reply-form" method="post" action="<?= e(url('threads', ['phone' => $phone])) ?>"
      hx-post="<?= e(url('threads', ['phone' => $phone])) ?>" hx-target="#thread-body" hx-swap="outerHTML"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?= csrf_field() ?>
<?php if ($error): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="row">
<label for="reply" class="visually-hidden"><?= e(t('threads.reply')) ?></label>
<textarea id="reply" name="text" rows="2" placeholder="<?= e(t('threads.reply_ph')) ?>" data-sms data-sms-counter="reply-counter" data-sms-why="reply-why"<?= $oob ? ' autofocus' : '' ?>><?= e($text) ?></textarea>
<button type="submit" aria-label="<?= e(t('threads.send')) ?>"><?= icon('plain', 'icon-lg') ?></button></div>
<div class="row"><span class="sms-counter" id="reply-counter"></span><span class="reply-note"><?= e(t('threads.auto_refresh')) ?></span>
<label class="small"><input type="checkbox" name="translit" value="1" data-sms-translit="reply"<?= Settings::bool('translit_default') ? ' checked' : '' ?>> <span><?= e(t('threads.no_polish')) ?></span></label></div>
<p class="sms-why" id="reply-why" hidden></p>
</form>
