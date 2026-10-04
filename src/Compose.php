<?php
declare(strict_types=1);

/** Ekran „Nowa wiadomość” (rozdz. 2.3): plan wysyłki z formularza (podgląd na żywo przez htmx) i sama wysyłka. */
final class Compose
{
    /** Dane formularza z żądania (POST) albo wartości startowe. */
    public static function input(): array
    {
        return [
            'groups' => input_ids('groups'),
            'contacts' => input_ids('contacts'),
            'numbers' => input('numbers'),
            'text' => SmsText::normalize((string) ($_POST['text'] ?? '')),
            'translit' => isset($_POST['translit']),
            'report' => isset($_POST['report']),
            'flash' => isset($_POST['flash']),
            'priority' => isset($_POST['priority']),
            'send_at' => input('send_at'),
            'skip_window' => isset($_POST['skip_window']),
            'template' => input('template'),
        ];
    }

    public static function defaults(): array
    {
        return ['groups' => [], 'contacts' => [], 'numbers' => '', 'text' => '', 'translit' => Settings::bool('translit_default'),
            'report' => Settings::bool('report_default'), 'flash' => false, 'priority' => false, 'send_at' => '', 'skip_window' => false,
            'template' => ''];
    }

    /** Plan wysyłki: odbiorcy, części, tempo, okno, błędy blokujące i ostrzeżenia. */
    public static function plan(array $in): array
    {
        $r = Recipients::resolve($in['groups'], $in['contacts'], $in['numbers']);
        $count = count($r['list']);
        $multi = $count > 1;
        $longest = Recipients::longest($in['text'], $r['list'], $in['translit']);
        $a = $longest['analysis'];
        $perMin = $multi ? max(0, Settings::int('throttle_per_min')) : 0;

        $errors = [];
        $sendAt = null;
        if ($in['send_at'] !== '') {
            $t = strtotime($in['send_at']);
            if ($t === false) {
                $errors[] = t('compose.err_date');
            } elseif ($t > time() + 60) {
                $sendAt = $t;
            }
        }
        $window = $multi && !$in['skip_window'] ? Recipients::window() : null;
        $start = $sendAt ?? time();
        if ($window !== null) {
            $start = Recipients::windowStart($window, $start);
        }
        $delayed = $start > time() + 60;

        if (trim($in['text']) === '') {
            $errors[] = t('compose.err_text');
        }
        if ($count === 0 && $r['invalid'] === []) {
            $errors[] = t('compose.err_recipients');
        }
        if ($r['invalid'] !== []) {
            $errors[] = t('compose.err_invalid', ['n' => count($r['invalid'])]);
        }
        if ($count > (int) cfg('max_recipients', 500)) {
            $errors[] = t('compose.err_too_many', ['n' => $count, 'max' => cfg('max_recipients', 500)]);
        }
        if ($a['too_long']) {
            $errors[] = t('sms.too_long', ['parts' => $a['parts'], 'max' => SmsText::MAX_PARTS]);
        }
        $warnings = [];
        if ($r['blocked'] !== []) {
            $warnings[] = tn('compose.warn_blocked', count($r['blocked']), ['list' => implode(', ', array_map([Phone::class, 'format'], array_slice($r['blocked'], 0, 5)))]);
        }
        $varsMissing = Recipients::usesVars($in['text']) ? $r['outside'] : 0;

        $preview = null;
        if (Recipients::usesVars($in['text']) && $count > 0) {
            $who = array_values(array_filter($r['list'], static fn ($x) => $x['name'] !== null))[0] ?? $r['list'][0];
            $t = Recipients::personalize($in['text'], $who['name']);
            $preview = ['name' => $who['name'] ?? Phone::format($who['phone']), 'text' => $in['translit'] ? SmsText::translit($t) : $t];
        }

        return [
            'recipients' => $r, 'count' => $count, 'multi' => $multi, 'analysis' => $a, 'parts' => max(1, $a['parts']),
            'total' => $count * max(1, $a['parts']), 'per_min' => $perMin, 'duration' => Recipients::duration($count, $perMin),
            'window' => $window, 'start' => $start, 'delayed' => $delayed, 'send_at' => $sendAt, 'errors' => $errors,
            'warnings' => $warnings, 'vars_missing' => $varsMissing, 'preview' => $preview,
            'window_settings' => Recipients::window(),
        ];
    }

    /** „teraz”, „dziś 14:30”, „jutro 8:00”. */
    public static function startLabel(array $plan): string
    {
        return $plan['delayed'] ? fmt_when(date('Y-m-d H:i:s', $plan['start'])) : t('compose.now');
    }

    /** Wysyłka wg planu; [batch_id lub null, id pierwszej wiadomości]. */
    public static function send(array $in, array $plan): array
    {
        $list = $plan['recipients']['list'];
        $multi = $plan['multi'];
        $batch = $multi ? bin2hex(random_bytes(8)) : null;
        $times = Recipients::schedule(count($list), $plan['per_min'], $plan['start']);
        $priority = !$multi || $in['priority'] ? Outbox::PRIORITY_SINGLE : Outbox::PRIORITY_BULK;
        // Cała wysyłka w jednej transakcji: błąd przy którymkolwiek odbiorcy nie zostawia części wiadomości w kolejce,
        // a Gammu widzi wysyłkę dopiero w całości
        $first = Db::tx(static function () use ($list, $in, $plan, $times, $priority, $batch): int {
            $first = null;
            foreach ($list as $i => $r) {
                $id = Outbox::create([
                    'phone' => $r['phone'],
                    'body' => Recipients::personalize($in['text'], $r['name']),
                    'translit' => $in['translit'],
                    'flash' => $in['flash'],
                    'report' => $in['report'],
                    'send_at' => $times[$i] > now_db() ? $times[$i] : null,
                    'priority' => $priority,
                    'window' => $plan['window'],
                    'batch_id' => $batch,
                ]);
                $first ??= $id;
            }
            if ($batch !== null) {
                Settings::set('batch_' . $batch, ['text' => $in['text'], 'label' => self::batchLabel($in), 'per_min' => $plan['per_min'],
                    'report' => $in['report'], 'created' => now_db()]);
            }
            return (int) $first;
        });
        app_log('info', 'wysyłka: ' . count($list) . ' odbiorców' . ($batch ? " (batch $batch)" : ''));
        return [$batch, $first];
    }

    /** Opis wysyłki do raportu: „grupa „Serwis””, „3 kontakty”, … */
    private static function batchLabel(array $in): string
    {
        $parts = [];
        if ($in['groups'] !== []) {
            $names = Db::col('SELECT name FROM `groups` WHERE id IN (' . Db::in($in['groups']) . ') ORDER BY name', $in['groups']);
            $parts[] = tn('compose.label_group', count($names), ['names' => implode(t('compose.label_group_sep'), $names)]);
        }
        if ($in['contacts'] !== []) {
            $n = count($in['contacts']);
            $parts[] = tn('compose.label_contacts', $n);
        }
        $manual = count(Recipients::splitManual($in['numbers']));
        if ($manual > 0) {
            $parts[] = tn('compose.label_numbers', $manual);
        }
        return implode(' + ', $parts);
    }
}
