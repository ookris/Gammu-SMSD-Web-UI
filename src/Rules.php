<?php
declare(strict_types=1);

/** Reguły przekazywania odebranych SMS i odrzuconych połączeń do kanałów (e-mail, webhook, SMS). */
final class Rules
{
    public const EVENTS = ['sms', 'call'];
    public const CONDITIONS = ['all', 'number', 'group', 'text'];
    private const BATCH = 200;

    public static function all(string $kind = 'forward'): array
    {
        return Db::all('SELECT r.*, c.name AS channel_name FROM rules r LEFT JOIN channels c ON c.id = r.channel_id WHERE r.kind = ? ORDER BY r.id', [$kind]);
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM rules WHERE id = ?', [$id]);
    }

    /**
     * Zapis reguły przekazywania. Zwraca [id|null, błąd|null].
     * @param array{name:string,event:string,cond_type:string,number?:string,group?:int,text?:string,channel_id:int} $in
     */
    public static function save(?int $id, array $in): array
    {
        $event = in_array($in['event'], self::EVENTS, true) ? $in['event'] : 'sms';
        $type = in_array($in['cond_type'], self::CONDITIONS, true) ? $in['cond_type'] : 'all';
        if ($event === 'call' && $type === 'text') {
            return [null, t('rules.err_call_text')];
        }
        if (Channels::find((int) $in['channel_id']) === null) {
            return [null, t('rules.err_channel')];
        }
        $value = '';
        if ($type === 'number') {
            $value = Phone::normalizeSender((string) ($in['number'] ?? '')) ?? '';
            if ($value === '') {
                return [null, t('rules.err_number')];
            }
        } elseif ($type === 'group') {
            $value = (string) (int) ($in['group'] ?? 0);
            if (Db::val('SELECT 1 FROM `groups` WHERE id = ?', [(int) $value]) === null) {
                return [null, t('rules.err_group')];
            }
        } elseif ($type === 'text') {
            $value = mb_substr(trim((string) ($in['text'] ?? '')), 0, 255);
            if ($value === '') {
                return [null, t('rules.err_text')];
            }
        }
        $name = mb_substr(trim($in['name']), 0, 120);
        if ($name === '') {
            $name = self::describe(['event' => $event, 'cond_type' => $type, 'cond_value' => $value]);
        }
        $data = ['name' => $name, 'event' => $event, 'cond_type' => $type, 'cond_value' => $value, 'channel_id' => (int) $in['channel_id']];
        if ($id === null) {
            return [Db::insert('rules', $data + ['kind' => 'forward', 'enabled' => 1, 'created_at' => now_db()]), null];
        }
        if (self::find($id) === null) {
            return [null, t('rules.err_missing')];
        }
        Db::update('rules', $data, 'id = ?', [$id]);
        return [$id, null];
    }

    public static function setEnabled(int $id, bool $on): void
    {
        Db::update('rules', ['enabled' => (int) $on], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM rules WHERE id = ?', [$id]);
    }

    /** Wysyła próbne zdarzenie reguły do jej kanału od razu (bez kolejki); zwraca opis błędu albo null. */
    public static function test(int $id): ?string
    {
        $rule = self::find($id);
        $channel = $rule ? Channels::find((int) $rule['channel_id']) : null;
        if ($rule === null || $channel === null) {
            return t('rules.err_missing');
        }
        $sample = ['event' => $rule['event'], 'phone' => '48600000000', 'body' => t('rules.test_body'), 'modem' => 'GSM1', 'at' => now_db()];
        try {
            Channels::make($channel)->send(self::format($sample));
            Db::update('rules', ['last_error' => null], 'id = ?', [$id]);
            return null;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 250);
            Db::update('rules', ['last_error' => $msg], 'id = ?', [$id]);
            return $msg;
        }
    }

    /** Opis reguły w jednym zdaniu, np. „SMS zawierające „faktura””. */
    public static function describe(array $r): string
    {
        $what = t($r['event'] === 'call' ? 'rules.ev_call' : 'rules.ev_sms');
        return match ($r['cond_type']) {
            'number' => t('rules.d_number', ['what' => $what, 'who' => Contacts::name($r['cond_value']) ?? Phone::format($r['cond_value'])]),
            'group' => t('rules.d_group', ['what' => $what, 'group' => (string) Db::val('SELECT name FROM `groups` WHERE id = ?', [(int) $r['cond_value']])]),
            'text' => t('rules.d_text', ['what' => $what, 'text' => $r['cond_value']]),
            default => t('rules.d_all', ['what' => $what]),
        };
    }

    /** Czy zdarzenie ['event','phone','body'] spełnia warunek reguły. */
    public static function matches(array $rule, array $e): bool
    {
        if ($rule['event'] !== $e['event']) {
            return false;
        }
        return match ($rule['cond_type']) {
            'all' => true,
            'number' => strcasecmp((string) $rule['cond_value'], (string) $e['phone']) === 0,
            'group' => Db::val('SELECT 1 FROM contacts c JOIN contact_group_members m ON m.contact_id = c.id WHERE c.phone = ? AND m.group_id = ?',
                [$e['phone'], (int) $rule['cond_value']]) !== null,
            'text' => $e['event'] === 'sms' && mb_stripos((string) ($e['body'] ?? ''), (string) $rule['cond_value']) !== false,
            default => false,
        };
    }

    /** @return array{subject:string,text:string,data:array<string,mixed>} */
    public static function format(array $e): array
    {
        $unknown = $e['phone'] === '';
        $who = $unknown ? t('rules.unknown_number') : Contacts::display($e['phone']);
        $name = $unknown ? null : Contacts::name($e['phone']);
        $when = fmt_when((string) $e['at']);
        $modem = (string) ($e['modem'] ?? '');
        $foot = $when . ($modem !== '' ? " · $modem" : '');
        $call = $e['event'] === 'call';
        return [
            'subject' => t($call ? 'rules.subject_call' : 'rules.subject_sms', ['who' => $who]),
            'text' => $call ? t('rules.call_text', ['who' => $who, 'time' => $when]) . ($modem !== '' ? " ($modem)" : '') : $e['body'] . "\n\n— " . $who . ' · ' . $foot,
            'data' => ['type' => $call ? 'call' : 'sms', 'from' => $unknown ? '' : (Phone::isAlpha($e['phone']) ? $e['phone'] : '+' . $e['phone']), 'name' => $name,
                'received_at' => $e['at'], 'modem' => $modem !== '' ? $modem : null],
        ];
    }

    /**
     * Przetwarza wiadomości i połączenia przyjęte od ostatniego przebiegu (kursory w ustawieniach) i kolejkuje dostarczenia.
     * Kursor przesuwa się razem z zakolejkowaniem w jednej transakcji, więc nic nie ginie ani nie dubluje się.
     */
    public static function process(): int
    {
        $rules = array_values(array_filter(self::all(), static fn ($r) => $r['enabled'] && $r['channel_id'] !== null));
        $queued = 0;
        foreach ([
            ['sms', 'rules_msg_cursor', "SELECT id, phone, body, modem, COALESCE(received_at, created_at) AS at FROM messages WHERE direction = 'in' AND id > ? ORDER BY id LIMIT " . self::BATCH],
            ['call', 'rules_call_cursor', 'SELECT id, IF(phone = \'\', raw_number, phone) AS phone, \'\' AS body, modem, received_at AS at FROM calls WHERE id > ? ORDER BY id LIMIT ' . self::BATCH],
        ] as [$event, $key, $sql]) {
            $cursor = (int) Settings::get($key, '0');
            $rows = Db::all($sql, [$cursor]);
            if ($rows === []) {
                continue;
            }
            $queued += Db::tx(static function () use ($rows, $rules, $event, $key): int {
                $n = 0;
                foreach ($rows as $row) {
                    if (Blocklist::isBlocked((string) $row['phone'])) {
                        continue;
                    }
                    $e = ['event' => $event, 'phone' => (string) $row['phone'], 'body' => (string) $row['body'], 'modem' => $row['modem'], 'at' => $row['at']];
                    foreach ($rules as $rule) {
                        if (self::matches($rule, $e) && self::deliver($rule, $e)) {
                            $n++;
                        }
                    }
                }
                Settings::set($key, (int) end($rows)['id']);
                return $n;
            });
        }
        return $queued;
    }

    private static function deliver(array $rule, array $e): bool
    {
        $channel = Channels::find((int) $rule['channel_id']);
        if ($channel === null || !$channel['enabled']) {
            return false;
        }
        // SMS na numer nadawcy wiadomości zamknąłby pętlę (odpowiedź wróciłaby jako kolejna wiadomość do przekazania)
        if ($channel['type'] === 'sms' && (Channels::config($channel)['number'] ?? null) === $e['phone']) {
            return false;
        }
        Deliveries::enqueue((int) $channel['id'], 'forward', self::format($e));
        Db::exec('UPDATE rules SET hits = hits + 1, last_hit_at = ? WHERE id = ?', [now_db(), $rule['id']]);
        return true;
    }
}
