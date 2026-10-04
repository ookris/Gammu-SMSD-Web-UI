<?php
declare(strict_types=1);

/** Wspólne fragmenty widoków: etykiety statusów, stronicowanie, komórka „kto”, puste listy. */
final class Ui
{
    public const PER_PAGE = 50;

    /** Etykieta statusu wiadomości + linia z opisem (rozdz. 3.6). */
    public static function status(array $m, bool $withSub = true): string
    {
        $s = $m['status'];
        $retrying = $s === 'queued' && (int) ($m['retries'] ?? 0) > 0;
        [$class, $label] = match (true) {
            $s === 'received' => ['badge-in', t('status.received')],
            $s === 'scheduled', $s === 'cancelled' => ['badge-neutral', t('status.' . $s)],
            $retrying => ['badge-warn', t('status.retrying')],
            $s === 'queued' => ['badge-warn', t('status.queued')],
            $s === 'sent' => ['badge-info', t('status.sent')],
            $s === 'delivered' => ['badge-ok', t('status.delivered')],
            $s === 'undelivered', $s === 'failed' => ['badge-err', t('status.' . $s)],
            default => ['', $s],
        };
        $sub = '';
        if ($withSub) {
            $sub = match (true) {
                $s === 'scheduled' => self::scheduledNote($m),
                $retrying => (string) $m['error'],
                $s === 'queued' => 'od ' . fmt_duration(max(0, time() - (int) ts($m['scheduled_at'] ?? $m['created_at']))),
                $s === 'delivered' => $m['delivered_at'] ? date('H:i:s', (int) ts($m['delivered_at'])) : '',
                default => (string) ($m['error'] ?? ''),
            };
        }
        return '<span class="badge ' . $class . '">' . e($label) . '</span>' . ($sub !== '' ? '<span class="sub">' . e($sub) . '</span>' : '');
    }

    private static function scheduledNote(array $m): string
    {
        $t = ts($m['scheduled_at'] ?? null);
        if ($t !== null && $t > time()) {
            return ($m['batch_id'] ? 'dławienie: ' : '') . fmt_when($m['scheduled_at']);
        }
        if ((int) ($m['send_window'] ?? 0) === 1 && ($w = Recipients::window()) !== null) {
            return 'okno wysyłki: od ' . substr($w[0], 0, 5);
        }
        return '';
    }

    /** Nazwa kontaktu (pogrubiona) i numer pod spodem albo sam numer. */
    public static function who(string $phone, bool $sub = true): string
    {
        $name = Contacts::name($phone);
        if ($name === null) {
            return '<strong>' . e(Phone::format($phone)) . '</strong>';
        }
        return '<strong>' . e($name) . '</strong>' . ($sub ? '<span class="sub">' . e(Phone::format($phone)) . '</span>' : '');
    }

    /** Stronicowanie: „1–50 z 87 · 50 na stronę” + Poprzednia / Następna. */
    public static function pager(int $total, int $page, string $pageName, array $params, int $per = self::PER_PAGE): string
    {
        $from = $total === 0 ? 0 : ($page - 1) * $per + 1;
        $to = min($total, $page * $per);
        $html = '<div class="pager"><span>' . $from . '–' . $to . ' z ' . number_format($total, 0, ',', ' ')
            . ($total > $per ? ' · ' . $per . ' na stronę' : '') . '</span><nav aria-label="Strony">';
        if ($page > 1) {
            $html .= '<a href="' . e(url($pageName, ['page' => $page - 1] + $params)) . '" role="button" class="secondary outline btn-sm">Poprzednia</a>';
        }
        if ($to < $total) {
            $html .= '<a href="' . e(url($pageName, ['page' => $page + 1] + $params)) . '" role="button" class="secondary outline btn-sm">Następna</a>';
        }
        return $html . '</nav></div>';
    }

    public static function page(): int
    {
        return max(1, (int) input('page', '1'));
    }

    public static function empty(string $iconName, string $title, string $text, string $actionHtml = ''): string
    {
        return '<div class="empty"><span class="badge-icon">' . icon($iconName, 'icon-xl') . '</span><strong>' . e($title) . '</strong>'
            . ($text !== '' ? '<p>' . e($text) . '</p>' : '') . $actionHtml . '</div>';
    }

    /** Atrybuty przycisku z oknem potwierdzenia (app.js): data-confirm. */
    public static function confirm(string $title, string $text = '', string $ok = 'Potwierdź'): string
    {
        return ' data-confirm="' . e($title) . '" data-confirm-text="' . e($text) . '" data-confirm-ok="' . e($ok) . '"';
    }

    /** Fragment treści z zaznaczeniem, że jest dłuższa. */
    public static function snippet(string $text, int $len = 90): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
    }

    /** Pola ukryte z bieżącymi filtrami (powrót po akcji). */
    public static function backField(): string
    {
        return '<input type="hidden" name="back" value="' . e(current_url()) . '">';
    }

    /** Ikona z etykietą odczytywaną przez czytniki ekranu. */
    public static function iconBtnLink(string $href, string $iconName, string $label, string $class = 'icon-btn'): string
    {
        return '<a href="' . e($href) . '" class="' . e($class) . '" aria-label="' . e($label) . '" title="' . e($label) . '">' . icon($iconName) . '</a>';
    }
}
