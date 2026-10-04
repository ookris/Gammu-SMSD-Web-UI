<?php /** @var array $rows @var int $total @var int $page @var array $f @var int $month @var bool $enabled @var array $blocked */ ?>
<header class="page-head"><div><h1>Połączenia</h1><p class="sub">Odrzucone połączenia przychodzące · <?= $month ?> w tym miesiącu</p></div></header>
<?= flashes_html() ?>
<?php if ($enabled): ?>
<?= alert('info', 'Zapisywanie połączeń jest włączone.', 'Gammu odrzuca każde połączenie przychodzące (HangupCalls = yes) – dzwoniący słyszy rozłączenie. Gammu nie potrafi zapisywać połączeń bez ich odrzucania.', '<a href="' . e(url('settings')) . '">Ustawienia panelu</a>', false) ?>
<?php else: ?>
<?= alert('warn', 'Zapisywanie połączeń jest wyłączone.', 'Włącz „Odrzucaj połączenia przychodzące i zapisuj je w panelu” w ustawieniach – zmiana przejdzie przez okno potwierdzenia zapisu konfiguracji Gammu.', '<a href="' . e(url('settings')) . '">Ustawienia panelu</a>', false) ?>
<?php endif ?>
<section class="card flush">
<form class="toolbar" method="get" action="./"><input type="hidden" name="p" value="calls">
<div class="field grow"><label for="q">Numer lub kontakt</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="np. 601 234"></div>
<div class="field"><label for="from">Od</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
<div class="field"><label for="to">Do</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
<button type="submit" class="secondary"><?= icon('filter') ?>Filtruj</button></form>
<?php if ($rows === []): ?><?= Ui::empty('end-call', array_filter($f) ? 'Brak wyników' : 'Brak zapisanych połączeń', array_filter($f) ? 'Zmień filtry.' : 'Odrzucone połączenia pojawią się tutaj.') ?><?php else: ?>
<form method="post" action="<?= e(url('calls')) ?>"><?= csrf_field() ?><?= Ui::backField() ?>
<div class="bulkbar" hidden><strong>Zaznaczono <span data-bulk-count>0</span></strong>
<button type="submit" class="btn-danger-outline btn-sm"<?= Ui::confirm('Usunąć zaznaczone połączenia?', '', 'Usuń') ?>><?= icon('trash-bin-trash') ?>Usuń</button></div>
<div class="table-wrap"><table>
<thead><tr><th scope="col" class="check"><input type="checkbox" data-check-all aria-label="Zaznacz wszystkie"></th><th scope="col">Data</th><th scope="col">Numer / kontakt</th><th scope="col">Modem</th><th scope="col">Akcje</th></tr></thead>
<tbody>
<?php foreach ($rows as $c): $hidden = $c['phone'] === ''; $isBlocked = in_array($c['phone'], $blocked, true); $known = !$hidden && Contacts::name($c['phone']) !== null; ?>
<tr><td class="check"><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Zaznacz wiersz"></td>
<td class="nowrap"><?= e(fmt_when($c['received_at'])) ?></td>
<td><?= $hidden ? '<strong>' . e($c['raw_number'] === 'nieprawidłowy numer' ? 'nieprawidłowy numer' : 'numer ukryty') . '</strong>' : Ui::who($c['phone']) ?><?= $isBlocked ? ' <span class="badge badge-err">zablokowany</span>' : '' ?></td>
<td><?= e($c['modem'] ?: '—') ?></td>
<td><div class="cell-actions">
<?php if (!$hidden): ?><?= Ui::iconBtnLink(url('compose', ['to' => $c['phone']]), 'plain', 'Wyślij SMS') ?>
<?php if (!$known): ?><?= Ui::iconBtnLink(url('contact', ['phone' => $c['phone']]), 'user-plus', 'Dodaj do kontaktów') ?><?php endif ?>
<?php if (!$isBlocked): ?><button type="submit" name="block" value="<?= e($c['phone']) ?>" class="icon-btn danger" aria-label="Zablokuj" title="Zablokuj"<?= Ui::confirm('Zablokować ' . Contacts::display($c['phone']) . '?', 'SMS od tego numeru nie będą trafiać do panelu.', 'Zablokuj') ?>><?= icon('forbidden-circle') ?></button><?php endif ?>
<?php endif ?>
<button type="submit" name="remove" value="<?= (int) $c['id'] ?>" class="icon-btn danger" aria-label="Usuń" title="Usuń"><?= icon('trash-bin-trash') ?></button>
</div></td></tr>
<?php endforeach ?>
</tbody></table></div>
</form>
<?= Ui::pager($total, $page, 'calls', $f) ?>
<?php endif ?>
</section>
