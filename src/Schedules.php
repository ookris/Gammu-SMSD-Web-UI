<?php
declare(strict_types=1);

/** Wiadomości cykliczne: codziennie, w wybrane dni tygodnia albo w wybranym dniu miesiąca, o stałej godzinie. */
final class Schedules
{
    /** Spóźnienie, po którym zaległe uruchomienie jest pomijane (np. po długiej awarii) zamiast wysyłać wiadomość „na wczoraj”. */
    public const MAX_LATE = 43200;

    public static function all(): array
    {
        return Db::all('SELECT * FROM schedules ORDER BY name, id');
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM schedules WHERE id = ?', [$id]);
    }

    /**
     * Zapis harmonogramu. Zwraca [id|null, błąd|null].
     * @param array{name:string,groups?:list<int>,numbers?:string,text:string,translit?:bool,report?:bool,flash?:bool,frequency:string,
     *   weekdays?:list<int>,month_day?:int,time:string} $in
     */
    public static function save(?int $id, array $in): array
    {
        $freq = in_array($in['frequency'], ['daily', 'weekly', 'monthly'], true) ? $in['frequency'] : 'daily';
        $groups = array_values(array_unique(array_map('intval', $in['groups'] ?? [])));
        if ($groups !== []) {
            $groups = array_map('intval', Db::col('SELECT id FROM `groups` WHERE id IN (' . Db::in($groups) . ')', $groups));
        }
        $numbers = trim((string) ($in['numbers'] ?? ''));
        $r = Recipients::resolve($groups, [], $numbers);
        if ($r['invalid'] !== []) {
            return [null, t('compose.err_invalid', ['n' => count($r['invalid'])])];
        }
        if ($groups === [] && $r['list'] === []) {
            return [null, t('schedules.err_recipients')];
        }
        $text = SmsText::normalize((string) $in['text']);
        $a = SmsText::analyze(Recipients::personalize($text, str_repeat('x', 30)));
        if (trim($text) === '') {
            return [null, t('compose.err_text')];
        }
        if ($a['too_long']) {
            return [null, t('sms.too_long', ['parts' => $a['parts'], 'max' => SmsText::MAX_PARTS])];
        }
        if (!Recipients::validTime((string) $in['time'])) {
            return [null, t('schedules.err_time')];
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $in['weekdays'] ?? []), static fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if ($freq === 'weekly' && $days === []) {
            return [null, t('schedules.err_weekdays')];
        }
        $monthDay = (int) ($in['month_day'] ?? 1);
        if ($freq === 'monthly' && ($monthDay < 1 || $monthDay > 31)) {
            return [null, t('schedules.err_month_day')];
        }
        $name = mb_substr(trim($in['name']), 0, 120);
        $data = [
            'name' => $name !== '' ? $name : Ui::snippet($text, 40), 'groups_json' => json_encode($groups), 'numbers' => $numbers, 'body' => $text,
            'translit' => (int) !empty($in['translit']), 'report' => (int) !empty($in['report']), 'flash' => (int) !empty($in['flash']),
            'frequency' => $freq, 'weekdays' => implode(',', $freq === 'weekly' ? $days : []), 'month_day' => $freq === 'monthly' ? $monthDay : 1,
            'at_time' => $in['time'] . ':00',
        ];
        $data['next_run_at'] = date('Y-m-d H:i:s', self::nextRun($data, time()));
        if ($id === null) {
            return [Db::insert('schedules', $data + ['enabled' => 1, 'created_at' => now_db(), 'last_error' => null]), null];
        }
        if (self::find($id) === null) {
            return [null, t('schedules.err_missing')];
        }
        Db::update('schedules', $data + ['last_error' => null], 'id = ?', [$id]);
        return [$id, null];
    }

    public static function setEnabled(int $id, bool $on): void
    {
        $s = self::find($id);
        if ($s !== null) {
            Db::update('schedules', ['enabled' => (int) $on, 'next_run_at' => date('Y-m-d H:i:s', self::nextRun($s, time()))], 'id = ?', [$id]);
        }
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM schedules WHERE id = ?', [$id]);
    }

    /** Najbliższe uruchomienie ściśle po chwili $after (czas lokalny; przy zmianie czasu 02:30 wiosną przesuwa się na 03:30). */
    public static function nextRun(array $s, int $after): int
    {
        $time = substr((string) $s['at_time'], 0, 8);
        $days = array_filter(array_map('intval', explode(',', (string) $s['weekdays'])));
        for ($i = 0; $i <= 400; $i++) {
            $day = strtotime(date('Y-m-d', $after) . " +$i days");
            $candidate = strtotime(date('Y-m-d', $day) . ' ' . $time);
            if ($candidate === false || $candidate <= $after) {
                continue;
            }
            $ok = match ($s['frequency']) {
                'weekly' => in_array((int) date('N', $candidate), $days, true),
                'monthly' => (int) date('j', $candidate) === min((int) $s['month_day'], (int) date('t', $candidate)),
                default => true,
            };
            if ($ok) {
                return $candidate;
            }
        }
        return $after + 86400;
    }

    /** Opis częstotliwości, np. „co tydzień: pon., śr. o 08:00”. */
    public static function describe(array $s): string
    {
        $time = substr((string) $s['at_time'], 0, 5);
        return match ($s['frequency']) {
            'weekly' => t('schedules.d_weekly', ['days' => implode(', ', array_map(static fn ($d) => lang_entry('schedules.days_short')[(int) $d - 1], array_filter(explode(',', (string) $s['weekdays'])))), 'time' => $time]),
            'monthly' => t('schedules.d_monthly', ['day' => $s['month_day'], 'time' => $time]),
            default => t('schedules.d_daily', ['time' => $time]),
        };
    }

    /** Krok synchronizacji: wysyła zaległe harmonogramy; zwraca liczbę uruchomień. */
    public static function process(): int
    {
        $n = 0;
        foreach (Db::all('SELECT * FROM schedules WHERE enabled = 1 AND next_run_at <= ? ORDER BY next_run_at LIMIT 20', [now_db()]) as $s) {
            $late = time() - (int) ts($s['next_run_at']);
            $next = date('Y-m-d H:i:s', self::nextRun($s, time()));
            if ($late > self::MAX_LATE) {
                app_log('warning', "harmonogram #{$s['id']}: pominięto uruchomienie spóźnione o {$late} s");
                Db::update('schedules', ['next_run_at' => $next, 'last_error' => t('schedules.skipped', ['when' => fmt_when($s['next_run_at'])])], 'id = ?', [$s['id']]);
                continue;
            }
            try {
                $in = [
                    'groups' => json_decode((string) $s['groups_json'], true) ?: [], 'contacts' => [], 'numbers' => (string) $s['numbers'], 'text' => (string) $s['body'],
                    'translit' => (bool) $s['translit'], 'report' => (bool) $s['report'], 'flash' => (bool) $s['flash'], 'priority' => false,
                    'send_at' => '', 'skip_window' => false, 'template' => '', 'validity' => Outbox::validity(Settings::get('validity_default')),
                ];
                $plan = Compose::plan($in);
                if ($plan['errors'] !== []) {
                    throw new RuntimeException(implode(' ', $plan['errors']));
                }
                Compose::send($in, $plan);
                Db::update('schedules', ['last_run_at' => now_db(), 'runs' => $s['runs'] + 1, 'last_error' => null, 'next_run_at' => $next], 'id = ?', [$s['id']]);
                $n++;
            } catch (Throwable $e) {
                if ($e instanceof PDOException) {
                    throw $e;
                }
                app_log('error', "harmonogram #{$s['id']}: " . $e->getMessage());
                Db::update('schedules', ['last_error' => mb_substr($e->getMessage(), 0, 250), 'next_run_at' => $next], 'id = ?', [$s['id']]);
            }
        }
        return $n;
    }
}
