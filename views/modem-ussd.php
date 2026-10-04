<?php /** @var ?array $current */ ?>
<div id="ussd-box"<?= $current && in_array($current['status'], ['queued', 'sent'], true) ? ' hx-get="' . e(url('modem', ['fragment' => 'ussd', 'ussd' => $current['id']])) . '" hx-trigger="every 2s" hx-swap="outerHTML"' : '' ?>>
<?php if ($current === null): ?>
<?php elseif (in_array($current['status'], ['queued', 'sent'], true)): ?>
<div class="ussd-wait" role="status"><?= icon('hourglass') ?><div><strong><?= e(t('modem.waiting')) ?></strong>
<div class="muted small"><?= e(t('modem.waiting_text', ['code' => $current['code'], 'n' => Ussd::TIMEOUT])) ?></div></div></div>
<?php elseif ($current['status'] === 'answered'): $menu = (int) $current['session_status'] === 3; ?>
<div class="ussd-answer" aria-live="polite">
<div class="head"><span><?= e(t('modem.answer_to', ['code' => $current['code']])) ?></span><?= view('partials/ussd-badge', ['r' => $current]) ?></div>
<pre><?= e((string) $current['response']) ?></pre>
<?php if ($menu): ?>
<form class="row row-end" method="post" action="<?= e(url('modem')) ?>"><?= csrf_field() ?><input type="hidden" name="parent" value="<?= (int) $current['id'] ?>">
<div class="field grow-1"><label for="ussd-reply"><?= e(t('modem.reply')) ?></label><input id="ussd-reply" name="reply" class="mono-input" required pattern="[0-9*#+]+" autofocus></div>
<button type="submit"><?= e(t('modem.reply')) ?></button><button type="submit" name="close" value="<?= (int) $current['id'] ?>" class="secondary outline" formnovalidate><?= e(t('modem.close_session')) ?></button></form>
<?php endif ?>
</div>
<?php else: ?>
<?= alert('err', $current['status'] === 'timeout' ? t('modem.timeout', ['code' => $current['code'], 'n' => Ussd::TIMEOUT]) : t('modem.send_failed', ['code' => $current['code']]), tr($current['response'])) ?>
<?php endif ?>
</div>
