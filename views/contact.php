<?php /** @var ?array $contact @var array $form @var array $errors @var array $groups @var array $selected @var array $feed @var bool $blocked */
$cc = cfg('default_country_code', '48');
?>
<header class="page-head"><div><h1><?= e($contact['name'] ?? 'Nowy kontakt') ?></h1>
<p class="sub">Kontakt<?= $contact ? ' · dodany ' . e(date('d.m.Y', (int) ts($contact['created_at']))) : '' ?> · <a href="<?= e(url('contacts')) ?>">wróć do listy</a></p></div>
<?php if ($contact): ?>
<form class="actions" method="post" action="<?= e(url('contact', ['id' => $contact['id']])) ?>"><?= csrf_field() ?>
<a href="<?= e(url('compose', ['contact' => $contact['id']])) ?>" role="button"><?= icon('plain') ?>Wyślij SMS</a>
<?php if ($blocked): ?><span class="badge badge-err">zablokowany</span><?php else: ?>
<button type="submit" name="block" value="1" class="secondary"<?= Ui::confirm('Zablokować ' . $contact['name'] . '?', 'SMS od tego numeru nie będą trafiać do panelu.', 'Zablokuj') ?>><?= icon('forbidden-circle') ?>Zablokuj</button><?php endif ?>
<button type="submit" name="delete" value="1" class="btn-danger-outline"<?= Ui::confirm('Usunąć kontakt ' . $contact['name'] . '?', 'Wiadomości i rozmowa zostaną – zamiast nazwy będzie widoczny numer ' . Phone::format($contact['phone']) . '.', 'Usuń kontakt') ?>><?= icon('trash-bin-trash') ?>Usuń</button>
</form>
<?php endif ?>
</header>
<?= flashes_html() ?>
<div class="split split-440">
<section class="card">
<header><h2>Dane kontaktu</h2></header>
<form class="stack-sm" method="post" action="<?= e(url('contact', ['id' => $contact['id'] ?? null])) ?>"><?= csrf_field() ?>
<div class="grid-2">
<div class="field"><label for="name">Nazwa</label><input id="name" name="name" type="text" value="<?= e($form['name']) ?>" required<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($errors['name'])): ?><small class="warn"><?= e($errors['name']) ?></small><?php endif ?></div>
<div class="field"><label for="phone">Numer telefonu</label>
<input id="phone" aria-describedby="phone-h" name="phone" type="text" value="<?= e($form['phone_input']) ?>" class="mono-input" required inputmode="tel"<?= isset($errors['phone']) ? ' aria-invalid="true"' : '' ?>>
<small id="phone-h"<?= isset($errors['phone']) ? ' class="warn"' : '' ?>><?= isset($errors['phone']) ? e($errors['phone']) : ($contact ? 'Zapisany jako ' . e(Phone::toGammu($contact['phone'])) . ' · ' : '') . 'numery ' . (int) cfg('national_number_length', 9) . '-cyfrowe dostają prefiks +' . e($cc) ?></small></div>
</div>
<div class="field"><label for="note">Notatka</label><textarea id="note" name="note" rows="3"><?= e((string) $form['note']) ?></textarea></div>
<fieldset><legend>Grupy</legend>
<div class="grid-2">
<?php foreach ($groups as $g): ?><label><input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $selected, true) ? ' checked' : '' ?>> <span><?= e($g['name']) ?></span></label><?php endforeach ?>
<?php if ($groups === []): ?><p class="muted small">Brak grup – <a href="<?= e(url('groups')) ?>">utwórz grupę</a>.</p><?php endif ?>
</div></fieldset>
<div class="row"><button type="submit"><?= icon('diskette') ?>Zapisz</button><a href="<?= e(url('contacts')) ?>" role="button" class="secondary outline">Anuluj</a></div>
</form>
</section>
<?php if ($contact): ?>
<section class="card">
<header><h2>Ostatnie wiadomości i połączenia</h2><a href="<?= e(url('threads', ['phone' => $contact['phone']])) ?>"><strong>Otwórz rozmowę</strong></a></header>
<?php if ($feed === []): ?><p class="muted">Brak wiadomości i połączeń z tym numerem.</p><?php endif ?>
<ul class="feed">
<?php foreach ($feed as $it): ?>
<?php if ($it['kind'] === 'call'): ?>
<li class="call"><?= icon('end-call') ?><div><div>Połączenie odrzucone</div><div class="meta"><?= e(fmt_when($it['at'])) ?></div></div></li>
<?php else: $out = $it['direction'] === 'out'; ?>
<li class="<?= $out ? 'out' : 'in' ?>"><?= icon($out ? 'plain' : 'inbox-in') ?><div><div><?= e(Ui::snippet($it['body'], 140)) ?></div>
<div class="meta"><?= e(fmt_when($it['at'])) ?><?= $out ? ' ·' . Ui::status($it, false) : '' ?></div></div></li>
<?php endif ?>
<?php endforeach ?>
</ul>
</section>
<?php endif ?>
</div>
