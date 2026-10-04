<?php /** @var ?array $current */ ?>
<div id="ussd-box"<?= $current && in_array($current['status'], ['queued', 'sent'], true) ? ' hx-get="' . e(url('modem', ['fragment' => 'ussd', 'ussd' => $current['id']])) . '" hx-trigger="every 2s" hx-swap="outerHTML"' : '' ?>>
<?php if ($current === null): ?>
<?php elseif (in_array($current['status'], ['queued', 'sent'], true)): ?>
<div class="ussd-wait" role="status"><?= icon('hourglass') ?><div><strong>Czekam na odpowiedź operatora…</strong>
<div class="muted small">Kod <?= e($current['code']) ?> · zwykle kilka sekund, najdłużej <?= Ussd::TIMEOUT ?> s</div></div></div>
<?php elseif ($current['status'] === 'answered'): $menu = (int) $current['session_status'] === 3; ?>
<div class="ussd-answer" aria-live="polite">
<div class="head"><span>Odpowiedź na <?= e($current['code']) ?></span><?= view('partials/ussd-badge', ['r' => $current]) ?></div>
<pre><?= e((string) $current['response']) ?></pre>
<?php if ($menu): ?>
<form class="row row-end" method="post" action="<?= e(url('modem')) ?>"><?= csrf_field() ?><input type="hidden" name="parent" value="<?= (int) $current['id'] ?>">
<div class="field grow-1"><label for="ussd-reply">Odpowiedz</label><input id="ussd-reply" name="reply" class="mono-input" required pattern="[0-9*#+]+" autofocus></div>
<button type="submit">Odpowiedz</button><button type="submit" name="close" value="<?= (int) $current['id'] ?>" class="secondary outline" formnovalidate>Zakończ sesję</button></form>
<?php endif ?>
</div>
<?php else: ?>
<?= alert('err', $current['status'] === 'timeout' ? 'Brak odpowiedzi na ' . $current['code'] . ' w ciągu ' . Ussd::TIMEOUT . ' s.' : 'Nie udało się wysłać ' . $current['code'] . '.', (string) $current['response']) ?>
<?php endif ?>
</div>
