<?php /** @var string $phone @var bool $oob @var ?string $error @var string $text */ ?>
<form class="reply" id="reply-form" method="post" action="<?= e(url('threads', ['phone' => $phone])) ?>"
      hx-post="<?= e(url('threads', ['phone' => $phone])) ?>" hx-target="#thread-body" hx-swap="outerHTML"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
<?= csrf_field() ?>
<?php if ($error): ?><?= alert('err', '', $error) ?><?php endif ?>
<div class="row">
<label for="reply" class="visually-hidden">Odpowiedź</label>
<textarea id="reply" name="text" rows="2" placeholder="Napisz odpowiedź…" data-sms data-sms-counter="reply-counter" data-sms-why="reply-why"<?= $oob ? ' autofocus' : '' ?>><?= e($text) ?></textarea>
<button type="submit" aria-label="Wyślij"><?= icon('plain', 'icon-lg') ?></button></div>
<div class="row"><span class="sms-counter" id="reply-counter"></span><span class="reply-note">· nowe wiadomości pojawiają się same (co 10 s)</span>
<label class="small"><input type="checkbox" name="translit" value="1" data-sms-translit="reply"<?= Settings::bool('translit_default') ? ' checked' : '' ?>> <span>bez polskich znaków</span></label></div>
<p class="sms-why" id="reply-why" hidden></p>
</form>
