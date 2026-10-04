<?php /** @var array $r */
if ($r['status'] === 'answered') {
    $s = (int) $r['session_status'];
    [$cls, $label] = match ($s) { 3 => ['badge-warn', t('ussd.waited_choice')], 2, 4 => ['', t('ussd.session.2')], 7 => ['badge-err', t('ussd.session.7')], default => ['', t('ussd.session.' . $s)] };
} else {
    [$cls, $label] = match ($r['status']) { 'timeout', 'failed' => ['badge-err', t('ussd.status.' . $r['status'])], default => ['badge-info', t('ussd.status.' . $r['status'])] };
}
?><span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
