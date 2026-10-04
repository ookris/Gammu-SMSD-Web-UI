<?php /** @var string $phone @var array $list @var string $q @var ?array $contact @var array $groups @var array $items @var string $version @var bool $blocked @var array $stats */ ?>
<header class="page-head"><div><h1>Rozmowy</h1><p class="sub"><?= (int) $stats['threads'] ?> <?= plural((int) $stats['threads'], 'rozmowa', 'rozmowy', 'rozmów') ?> · <?= (int) $stats['unread'] ?> <?= plural((int) $stats['unread'], 'nieprzeczytana', 'nieprzeczytane', 'nieprzeczytanych') ?></p></div>
<div class="actions"><a href="<?= e(url('compose')) ?>" role="button"><?= icon('add-circle') ?>Nowa wiadomość</a></div></header>
<?= flashes_html() ?>
<?php if ($list === [] && $q === ''): ?>
<section class="card"><?= Ui::empty('chat-round-line', 'Nie ma jeszcze żadnej rozmowy', 'Rozmowy pojawią się, gdy wyślesz SMS albo modem odbierze wiadomość.', '<a href="' . e(url('compose')) . '" role="button">' . icon('add-circle') . 'Nowa wiadomość</a>') ?></section>
<?php else: ?>
<div class="threads<?= $phone !== '' ? ' is-open' : '' ?>">
<div class="thread-list">
<form class="search" method="get" action="./"><input type="hidden" name="p" value="threads">
<input id="tq" name="q" type="search" value="<?= e($q) ?>" placeholder="Szukaj: nazwa, numer, treść" aria-label="Szukaj rozmowy"></form>
<ul>
<?php foreach ($list as $t): $label = Contacts::display($t['phone']); ?>
<li<?= $t['unread'] > 0 ? ' class="unread"' : '' ?>><a href="<?= e(url('threads', ['phone' => $t['phone']])) ?>"<?= $t['phone'] === $phone ? ' aria-current="page"' : '' ?>>
<span class="avatar" aria-hidden="true"><?= e(Contacts::initials(Contacts::name($t['phone']) ?? (Phone::isAlpha($t['phone']) ? $t['phone'] : ''))) ?></span>
<span class="name"><?= e($label) ?></span><span class="when"><?= e(fmt_short($t['received_at'] ?? $t['created_at'])) ?></span>
<span class="snippet"><?= e(Ui::snippet((string) $t['body'], 60)) ?></span><?= $t['unread'] > 0 ? '<span class="nav-count">' . $t['unread'] . '</span>' : '<span></span>' ?></a></li>
<?php endforeach ?>
<?php if ($list === []): ?><li class="muted small"><?= Ui::empty('magnifer', 'Brak wyników dla „' . $q . '”', 'Zmień frazę.') ?></li><?php endif ?>
</ul>
</div>
<?php if ($phone === ''): ?>
<section class="thread-view"><?= Ui::empty('chat-round-line', 'Wybierz rozmowę', 'Kliknij numer na liście albo napisz nową wiadomość.') ?></section>
<?php else: $alpha = Phone::isAlpha($phone); ?>
<section class="thread-view" aria-label="Rozmowa z <?= e(Contacts::display($phone)) ?>">
<header class="thread-head">
<a href="<?= e(url('threads')) ?>" class="icon-btn" aria-label="Wróć do listy" title="Wróć do listy"><?= icon('alt-arrow-left') ?></a>
<span class="avatar" aria-hidden="true"><?= e(Contacts::initials($contact['name'] ?? ($alpha ? $phone : ''))) ?></span>
<div><div class="name"><?= e(Contacts::display($phone)) ?><?= $blocked ? ' <span class="badge badge-err">zablokowany</span>' : '' ?></div>
<div class="sub"><?= $contact ? e(Phone::format($phone)) . ($groups ? ' · grupy: ' . e(implode(', ', $groups)) : '') : ($alpha ? 'nadawca z nazwą' : 'numer spoza książki') ?></div></div>
<?php if ($contact): ?><a href="<?= e(url('contact', ['id' => $contact['id']])) ?>"><strong>Kontakt</strong></a>
<?php elseif (!$alpha): ?><a href="<?= e(url('contact', ['phone' => $phone])) ?>"><strong>Dodaj do kontaktów</strong></a><?php endif ?>
<form method="post" action="<?= e(url('threads', ['phone' => $phone])) ?>" class="row"><?= csrf_field() ?>
<?php if (!$blocked): ?><button type="submit" name="block" value="1" class="icon-btn danger" aria-label="Zablokuj numer" title="Zablokuj numer"<?= Ui::confirm('Zablokować ' . Contacts::display($phone) . '?', 'SMS od tego numeru nie będą trafiać do panelu – Gammu usunie je z modemu.', 'Zablokuj') ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
<button type="submit" name="delete_thread" value="1" class="icon-btn danger" aria-label="Usuń rozmowę" title="Usuń rozmowę"<?= Ui::confirm('Usunąć rozmowę z ' . Contacts::display($phone) . '?', 'Usunięte zostaną wszystkie wiadomości i połączenia z tym numerem (poza czekającymi w kolejce).', 'Usuń rozmowę') ?>><?= icon('trash-bin-trash') ?></button>
</form>
</header>
<?= view('thread-body', ['phone' => $phone, 'items' => $items, 'version' => $version]) ?>
<?php if ($alpha): ?>
<p class="readonly-note"><?= icon('info-circle') ?>Na wiadomości od nadawcy z nazwą nie można odpowiedzieć.</p>
<?php else: ?>
<?= view('thread-reply', ['phone' => $phone, 'oob' => false, 'error' => null, 'text' => '']) ?>
<?php endif ?>
</section>
<?php endif ?>
</div>
<?php endif ?>
