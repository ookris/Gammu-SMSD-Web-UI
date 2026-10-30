<?php
declare(strict_types=1);

/** Reguły przekazywania odebranych SMS i odrzuconych połączeń do kanałów (e-mail, webhook, SMS). */
final class Rules
{
    public const EVENTS = ['sms', 'call'];
    public const CONDITIONS = ['all', 'number', 'group', 'text', 'equals', 'starts'];
    /** Warunki dotyczące treści – tylko dla SMS. */
    private const TEXT_CONDITIONS = ['text', 'equals', 'starts'];
    /** Najwyżej jedna autoodpowiedź do tego samego numeru w tym czasie (s). */
    private const REPLY_PAUSE = 3600;
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
     * Zapis reguły. Zwraca [id|null, błąd|null]. Przekazywanie wymaga kanału, autoodpowiedź – treści odpowiedzi.
     * @param array{name:string,event:string,cond_type:string,number?:string,group?:int,text?:string,channel_id?:int,reply_text?:string,
     *   hours_mode?:string,hours_from?:string,hours_to?:string,workdays?:bool} $in
     */
    public static function save(?int $id, array $in, string $kind = 'forward'): array
    {
        $event = in_array($in['event'], self::EVENTS, true) ? $in['event'] : 'sms';
        $type = in_array($in['cond_type'], self::CONDITIONS, true) ? $in['cond_type'] : 'all';
        if ($event === 'call' && in_array($type, self::TEXT_CONDITIONS, true)) {
            return [null, t('rules.err_call_text')];
        }
        $channel = null;
        $reply = null;
        if ($kind === 'forward') {
            $channel = (int) ($in['channel_id'] ?? 0);
            if (Channels::find($channel) === null) {
                return [null, t('rules.err_channel')];
            }
        } else {
            $reply = trim((string) ($in['reply_text'] ?? ''));
            if ($reply === '') {
                return [null, t('rules.err_reply')];
            }
            if (SmsText::analyze(Recipients::personalize($reply, str_repeat('x', 30)))['too_long']) {
                return [null, t('sms.too_long', ['parts' => SmsText::analyze($reply)['parts'], 'max' => SmsText::MAX_PARTS])];
            }
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
        } elseif (in_array($type, self::TEXT_CONDITIONS, true)) {
            $value = mb_substr(trim((string) ($in['text'] ?? '')), 0, 255);
            if ($value === '') {
                return [null, t('rules.err_text')];
            }
        }
        $mode = in_array($in['hours_mode'] ?? 'any', ['outside', 'inside'], true) && $kind === 'autoreply' ? $in['hours_mode'] : 'any';
        $from = $to = null;
        if ($mode !== 'any') {
            $from = Recipients::validTime((string) ($in['hours_from'] ?? '')) ? $in['hours_from'] . ':00' : null;
            $to = Recipients::validTime((string) ($in['hours_to'] ?? '')) ? $in['hours_to'] . ':00' : null;
            if ($from === null || $to === null || $from === $to) {
                return [null, t('rules.err_hours')];
            }
        }
        $name = mb_substr(trim($in['name']), 0, 120);
        if ($name === '') {
            $name = self::describe(['event' => $event, 'cond_type' => $type, 'cond_value' => $value]);
        }
        $data = ['name' => $name, 'event' => $event, 'cond_type' => $type, 'cond_value' => $value, 'channel_id' => $channel, 'reply_text' => $reply,
            'hours_mode' => $mode, 'hours_from' => $from, 'hours_to' => $to, 'workdays_only' => (int) (!empty($in['workdays']) && $mode !== 'any')];
        if ($id === null) {
            return [Db::insert('rules', $data + ['kind' => $kind, 'enabled' => 1, 'created_at' => now_db()]), null];
        }
        if (self::find($id) === null) {
            return [null, t('rules.err_missing')];
        }
        Db::update('rules', $data, 'id = ? AND kind = ?', [$id, $kind]);
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
            'equals' => t('rules.d_equals', ['what' => $what, 'text' => $r['cond_value']]),
            'starts' => t('rules.d_starts', ['what' => $what, 'text' => $r['cond_value']]),
            default => t('rules.d_all', ['what' => $what]),
        };
    }

    /** Czy zdarzenie ['event','phone','body'] spełnia warunek reguły. */
    public static function matches(array $rule, array $e): bool
    {
        if ($rule['event'] !== $e['event'] || !self::inHours($rule, strtotime((string) ($e['at'] ?? 'now')) ?: time())) {
            return false;
        }
        $body = mb_strtolower(trim((string) ($e['body'] ?? '')));
        $needle = mb_strtolower(trim((string) $rule['cond_value']));
        return match ($rule['cond_type']) {
            'all' => true,
            'number' => strcasecmp((string) $rule['cond_value'], (string) $e['phone']) === 0,
            'group' => Db::val('SELECT 1 FROM contacts c JOIN contact_group_members m ON m.contact_id = c.id WHERE c.phone = ? AND m.group_id = ?',
                [$e['phone'], (int) $rule['cond_value']]) !== null,
            'text' => $e['event'] === 'sms' && str_contains($body, $needle),
            'equals' => $e['event'] === 'sms' && $body === $needle,
            'starts' => $e['event'] === 'sms' && str_starts_with($body, $needle),
            default => false,
        };
    }

    /** Warunek godzin reguły: „poza” / „w” przedziale (może przechodzić przez północ); przy workdays_only poza dniami roboczymi liczy się jako „poza”. */
    public static function inHours(array $rule, int $at): bool
    {
        $mode = $rule['hours_mode'] ?? 'any';
        if ($mode === 'any' || empty($rule['hours_from']) || empty($rule['hours_to'])) {
            return true;
        }
        $time = date('H:i:s', $at);
        $from = (string) $rule['hours_from'];
        $to = (string) $rule['hours_to'];
        $inWindow = $from < $to ? ($time >= $from && $time < $to) : ($time >= $from || $time < $to);
        $work = !(int) ($rule['workdays_only'] ?? 0) || (int) date('N', $at) <= 5;
        $inside = $inWindow && $work;
        return $mode === 'inside' ? $inside : !$inside;
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
                'received_at' => $e['at'], 'modem' => $modem !== '' ? $modem : null] + ($call ? [] : ['body' => $e['body']]),
        ];
    }

    /**
     * Przetwarza wiadomości i połączenia przyjęte od ostatniego przebiegu (kursory w ustawieniach) i kolejkuje dostarczenia.
     * Kursor przesuwa się razem z zakolejkowaniem w jednej transakcji, więc nic nie ginie ani nie dubluje się.
     */
    public static function process(): int
    {
        $rules = array_values(array_filter(array_merge(self::all('forward'), self::all('autoreply')), static fn ($r) => $r['enabled'] && ($r['kind'] === 'autoreply' || $r['channel_id'] !== null)));
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
                    $replied = false;
                    foreach ($rules as $rule) {
                        if (!self::matches($rule, $e)) {
                            continue;
                        }
                        if ($rule['kind'] === 'autoreply') {
                            // Jedna odpowiedź na zdarzenie – pierwsza pasująca reguła
                            if (!$replied && self::reply($rule, $e)) {
                                $replied = true;
                                $n++;
                            }
                        } elseif (self::deliver($rule, $e)) {
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

    /** Autoodpowiedź SMS-em; pomija nadawców, którym nie da się odpowiedzieć, i numery, które dostały odpowiedź w ostatniej godzinie (pętla). */
    private static function reply(array $rule, array $e): bool
    {
        $phone = $e['phone'];
        if ($phone === '' || Phone::isAlpha($phone) || Phone::isShort($phone)) {
            return false;
        }
        if (Db::val("SELECT 1 FROM messages WHERE direction = 'out' AND source = 'autoreply' AND phone = ? AND created_at > ? LIMIT 1",
            [$phone, now_db(-self::REPLY_PAUSE)]) !== null) {
            return false;
        }
        try {
            Outbox::create(['phone' => $phone, 'body' => Recipients::personalize((string) $rule['reply_text'], Contacts::name($phone)),
                'translit' => Settings::bool('translit_default'), 'source' => 'autoreply', 'priority' => Outbox::PRIORITY_SINGLE,
                'modem' => Threads::replyModem($phone)]);
        } catch (InvalidArgumentException $ex) {
            Db::update('rules', ['last_error' => mb_substr($ex->getMessage(), 0, 250)], 'id = ?', [$rule['id']]);
            return false;
        }
        Db::exec('UPDATE rules SET hits = hits + 1, last_hit_at = ?, last_error = NULL WHERE id = ?', [now_db(), $rule['id']]);
        return true;
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
