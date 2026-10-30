<?php
declare(strict_types=1);

/** Kopia danych panelu w jednym pliku JSON: kontakty, grupy, szablony, czarna lista i ustawienia (bez wiadomości i haseł). */
final class Backup
{
    public const FORMAT = 1;
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Ustawienia w kopii: klucz => walidator zwracający wartość do zapisu albo null (pominąć). */
    private static function settingRules(): array
    {
        $int = static fn (int $min, int $max) => static fn (mixed $v) => is_numeric($v) && (int) $v >= $min && (int) $v <= $max ? (string) (int) $v : null;
        $bool = static fn (mixed $v) => in_array((string) $v, ['0', '1'], true) ? (string) $v : null;
        $time = static fn (mixed $v) => is_string($v) && Recipients::validTime($v) ? $v : null;
        return [
            'report_default' => $bool,
            'translit_default' => $bool,
            'validity_default' => static fn (mixed $v) => (string) Outbox::validity($v),
            'throttle_per_min' => $int(0, 60),
            'window_enabled' => $bool,
            'window_from' => $time,
            'window_to' => $time,
            'session_hours' => $int(1, 720),
            'backup_keep' => $int(1, 500),
            'cleanup_days' => $int(0, 3650),
            'balance_code' => static fn (mixed $v) => is_string($v) && preg_match('/^([0-9*#+]{1,20})?$/', $v) ? $v : null,
            'balance_hours' => $int(0, 720),
            'balance_regex' => static fn (mixed $v) => is_string($v) && Balance::validRegex($v) ? $v : null,
            'balance_threshold' => static fn (mixed $v) => is_numeric($v) && (float) $v >= 0 && (float) $v <= 100000 ? (string) round((float) $v, 2) : null,
            'monthly_limit' => $int(0, 1000000),
            'lang' => static fn (mixed $v) => is_string($v) && isset(LANGS[$v]) ? $v : null,
            'ussd_codes' => static function (mixed $v): ?string {
                $list = is_string($v) ? json_decode($v, true) : null;
                if (!is_array($list)) {
                    return null;
                }
                $out = [];
                foreach ($list as $c) {
                    $code = is_array($c) && is_string($c['code'] ?? null) ? str_replace(' ', '', $c['code']) : '';
                    if (preg_match('/^[0-9*#+]{1,20}$/', $code)) {
                        $out[] = ['name' => mb_substr(is_string($c['name'] ?? null) ? trim($c['name']) : '', 0, 40), 'code' => $code];
                    }
                }
                return json_encode($out, JSON_UNESCAPED_UNICODE);
            },
        ];
    }

    public static function export(): string
    {
        $groups = Db::col('SELECT name FROM `groups` ORDER BY name');
        $members = [];
        foreach (Db::all('SELECT m.contact_id, g.name FROM contact_group_members m JOIN `groups` g ON g.id = m.group_id ORDER BY g.name') as $r) {
            $members[(int) $r['contact_id']][] = $r['name'];
        }
        $contacts = [];
        foreach (Db::all('SELECT id, name, phone, note FROM contacts ORDER BY name, id') as $c) {
            $contacts[] = ['name' => $c['name'], 'phone' => $c['phone'], 'note' => $c['note'], 'groups' => $members[(int) $c['id']] ?? []];
        }
        $settings = [];
        foreach (array_keys(self::settingRules()) as $k) {
            $v = Settings::get($k);
            if ($v !== null) {
                $settings[$k] = $v;
            }
        }
        return json_encode([
            'app' => 'smsgui', 'format' => self::FORMAT, 'exported_at' => now_db(),
            'contacts' => $contacts, 'groups' => $groups,
            'templates' => Db::all('SELECT name, body FROM templates ORDER BY name, id'),
            'blocked' => Db::all('SELECT phone, note FROM blocked_numbers ORDER BY phone'),
            'settings' => $settings,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Wczytanie kopii: dopisuje i aktualizuje (kontakty wg numeru, grupy wg nazwy, szablony wg nazwy, czarna lista wg numeru,
     * ustawienia nadpisuje); niczego nie usuwa. Całość w jednej transakcji.
     * @return array{contacts:int,groups:int,templates:int,blocked:int,settings:int,skipped:int}
     * @throws InvalidArgumentException gdy plik nie jest kopią panelu
     */
    public static function import(string $json): array
    {
        $d = json_decode($json, true, 16);
        if (!is_array($d) || ($d['app'] ?? null) !== 'smsgui') {
            throw new InvalidArgumentException(t('backup.err_format'));
        }
        if ((int) ($d['format'] ?? 0) !== self::FORMAT) {
            throw new InvalidArgumentException(t('backup.err_version', ['v' => (string) ($d['format'] ?? '?')]));
        }
        $list = static fn (string $k): array => array_values(array_filter(is_array($d[$k] ?? null) ? $d[$k] : [], 'is_array'));
        $str = static fn (mixed $v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $n = ['contacts' => 0, 'groups' => 0, 'templates' => 0, 'blocked' => 0, 'settings' => 0, 'skipped' => 0];

        Db::tx(static function () use ($d, $list, $str, &$n): void {
            $now = now_db();
            $groupNames = array_map(static fn ($g) => $str($g, 190), is_array($d['groups'] ?? null) ? $d['groups'] : []);
            foreach ($list('contacts') as $c) {
                array_push($groupNames, ...array_map(static fn ($g) => $str($g, 190), is_array($c['groups'] ?? null) ? $c['groups'] : []));
            }
            foreach (array_unique(array_filter($groupNames, 'strlen')) as $name) {
                $n['groups'] += Db::exec('INSERT IGNORE INTO `groups` (name, created_at) VALUES (?, ?)', [$name, $now]);
            }
            foreach ($list('contacts') as $c) {
                $phone = Phone::normalize($str($c['phone'] ?? '', 32));
                $name = $str($c['name'] ?? '', 190);
                if ($phone === null || $name === '') {
                    $n['skipped']++;
                    continue;
                }
                Db::exec('INSERT INTO contacts (name, phone, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), note = VALUES(note), updated_at = VALUES(updated_at)',
                    [$name, $phone, is_string($c['note'] ?? null) ? $c['note'] : null, $now, $now]);
                $id = (int) Db::val('SELECT id FROM contacts WHERE phone = ?', [$phone]);
                foreach (is_array($c['groups'] ?? null) ? $c['groups'] : [] as $g) {
                    Db::exec('INSERT IGNORE INTO contact_group_members (contact_id, group_id) SELECT ?, id FROM `groups` WHERE name = ?', [$id, $str($g, 190)]);
                }
                $n['contacts']++;
            }
            foreach ($list('templates') as $t) {
                $name = $str($t['name'] ?? '', 190);
                $body = is_string($t['body'] ?? null) ? $t['body'] : '';
                if ($name === '' || trim($body) === '') {
                    $n['skipped']++;
                    continue;
                }
                $id = Db::val('SELECT id FROM templates WHERE name = ?', [$name]);
                $id === null
                    ? Db::insert('templates', ['name' => $name, 'body' => $body, 'created_at' => $now, 'updated_at' => $now])
                    : Db::update('templates', ['body' => $body, 'updated_at' => $now], 'id = ?', [$id]);
                $n['templates']++;
            }
            foreach ($list('blocked') as $b) {
                $phone = Phone::normalizeSender($str($b['phone'] ?? '', 32));
                if ($phone === null) {
                    $n['skipped']++;
                    continue;
                }
                Db::exec('INSERT INTO blocked_numbers (phone, note, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note)',
                    [$phone, $str($b['note'] ?? '', 255), $now]);
                $n['blocked']++;
            }
            $rules = self::settingRules();
            foreach (is_array($d['settings'] ?? null) ? $d['settings'] : [] as $k => $v) {
                $value = isset($rules[$k]) && (is_string($v) || is_int($v)) ? $rules[$k]($v) : null;
                if ($value === null) {
                    $n['skipped']++;
                    continue;
                }
                Settings::set($k, $value);
                $n['settings']++;
            }
        });
        Settings::forget();
        if ($n['blocked'] > 0) {
            Blocklist::applyQuietly();
        }
        return $n;
    }
}
