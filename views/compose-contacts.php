<?php /** @var array $contacts @var array $selected */ ?>
<?php foreach ($contacts as $c): ?>
<label><input type="checkbox" name="contacts[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $selected, true) ? ' checked' : '' ?>> <span><?= e($c['name']) ?></span><span class="muted"><?= e(Phone::format($c['phone'])) ?></span></label>
<?php endforeach ?>
<?php if ($contacts === []): ?><p class="muted small">Brak pasujących kontaktów.</p><?php endif ?>
