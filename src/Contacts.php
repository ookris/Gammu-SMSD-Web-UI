<?php
declare(strict_types=1);

/** Książka telefoniczna i grupy, import/eksport CSV, nazwy kontaktów w całym panelu. */
final class Contacts
{
    private static ?array $names = null;

    /** Mapa numer → nazwa kontaktu (pamięć na czas żądania). */
    public static function names(): array
    {
        return self::$names ??= array_column(Db::all('SELECT phone, name FROM contacts'), 'name', 'phone');
    }

    public static function name(string $phone): ?string
    {
        return self::names()[$phone] ?? null;
    }

    /** Nazwa kontaktu albo sformatowany numer. */
    public static function display(string $phone): string
    {
        return self::name($phone) ?? Phone::format($phone);
    }

    public static function forget(): void
    {
        self::$names = null;
    }

    public static function initials(string $label): string
    {
        if ($label === '' || preg_match('/^\+?[0-9 ]+$/', $label)) {
            return '#';
        }
        $words = preg_split('/\s+/u', trim($label)) ?: [];
        $out = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1));
        if (count($words) > 1) {
            $out .= mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1));
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM contacts WHERE id = ?', [$id]);
    }

    public static function byPhone(string $phone): ?array
    {
        return Db::row('SELECT * FROM contacts WHERE phone = ?', [$phone]);
    }

    /** Zapis kontaktu; [id, błędy pól]. */
    public static function save(?int $id, string $name, string $phoneInput, string $note, array $groupIds): array
    {
        $errors = [];
        $name = trim($name);
        if ($name === '') {
            $errors['name'] = t('contact.err_name');
        }
        $phone = Phone::normalize($phoneInput);
        if ($phone === null) {
            $errors['phone'] = t('contact.err_phone', ['why' => Phone::error($phoneInput)]);
        } else {
            $other = self::byPhone($phone);
            if ($other !== null && (int) $other['id'] !== $id) {
                $errors['phone'] = t('contact.err_exists', ['phone' => Phone::format($phone), 'name' => $other['name']]);
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        $id = Db::tx(static function () use ($id, $name, $phone, $note, $groupIds): int {
            $data = ['name' => mb_substr($name, 0, 190), 'phone' => $phone, 'note' => trim($note), 'updated_at' => now_db()];
            if ($id === null) {
                $id = Db::insert('contacts', $data + ['created_at' => now_db()]);
            } else {
                Db::update('contacts', $data, 'id = ?', [$id]);
                Db::exec('DELETE FROM contact_group_members WHERE contact_id = ?', [$id]);
            }
            foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
                Db::exec('INSERT IGNORE INTO contact_group_members (contact_id, group_id) SELECT ?, id FROM `groups` WHERE id = ?', [$id, $gid]);
            }
            return $id;
        });
        self::forget();
        return [$id, []];
    }

    public static function delete(array $ids): int
    {
        self::forget();
        return $ids === [] ? 0 : Db::exec('DELETE FROM contacts WHERE id IN (' . Db::in($ids) . ')', $ids);
    }

    /** Grupy z liczbą członków. */
    public static function groups(): array
    {
        return Db::all('SELECT g.id, g.name, COUNT(m.contact_id) AS members FROM `groups` g
            LEFT JOIN contact_group_members m ON m.group_id = g.id GROUP BY g.id, g.name ORDER BY g.name');
    }

    /** Nazwy grup dla kontaktów: [contact_id => [nazwa, …]]. */
    public static function groupsOf(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT m.contact_id, g.name FROM contact_group_members m JOIN `groups` g ON g.id = m.group_id
            WHERE m.contact_id IN (' . Db::in($contactIds) . ') ORDER BY g.name', $contactIds) as $r) {
            $out[(int) $r['contact_id']][] = $r['name'];
        }
        return $out;
    }

    public static function groupIdsOf(int $contactId): array
    {
        return array_map('intval', Db::col('SELECT group_id FROM contact_group_members WHERE contact_id = ?', [$contactId]));
    }

    /** Lista z wyszukiwaniem i filtrem grupy ('' – wszystkie, 'none' – bez grupy, id). [wiersze, liczba]. */
    public static function search(string $q, string $group, int $page, int $per = 50): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            $where[] = '(c.name LIKE ? OR c.note LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ')';
            array_push($params, "%$q%", "%$q%");
            if ($digits !== '') {
                $params[] = '%' . ltrim($digits, '0') . '%';
            }
        }
        if ($group === 'none') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = c.id)';
        } elseif (ctype_digit($group)) {
            $where[] = 'EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = c.id AND m.group_id = ?)';
            $params[] = (int) $group;
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM contacts c WHERE $w", $params);
        $rows = Db::all("SELECT c.*, (SELECT MAX(COALESCE(m.received_at, m.created_at)) FROM messages m WHERE m.phone = c.phone) AS last_sms
            FROM contacts c WHERE $w ORDER BY c.name LIMIT $per OFFSET " . (max(1, $page) - 1) * $per, $params);
        return [$rows, $total];
    }

    public static function addToGroup(array $ids, int $groupId): void
    {
        foreach ($ids as $id) {
            Db::exec('INSERT IGNORE INTO contact_group_members (contact_id, group_id) VALUES (?, ?)', [$id, $groupId]);
        }
    }

    public static function removeFromGroup(array $ids, int $groupId): void
    {
        if ($ids !== []) {
            Db::exec('DELETE FROM contact_group_members WHERE group_id = ? AND contact_id IN (' . Db::in($ids) . ')', [$groupId, ...$ids]);
        }
    }

    public static function groupByName(string $name, bool $create): ?int
    {
        $id = Db::val('SELECT id FROM `groups` WHERE name = ?', [$name]);
        if ($id === null && $create) {
            $id = Db::insert('`groups`', ['name' => mb_substr($name, 0, 190), 'created_at' => now_db()]);
        }
        return $id === null ? null : (int) $id;
    }

    // ---------- CSV: nazwa;numer;grupy;notatka, grupy oddzielone | ----------

    public static function csvExport(): string
    {
        $out = "\u{FEFF}" . t('contacts.csv_header') . "\r\n";
        $contacts = Db::all('SELECT id, name, phone, note FROM contacts ORDER BY name');
        $groups = self::groupsOf(array_map('intval', array_column($contacts, 'id')));
        foreach ($contacts as $c) {
            $out .= self::csvLine([$c['name'], Phone::toGammu($c['phone']), implode('|', $groups[(int) $c['id']] ?? []), (string) $c['note']]);
        }
        return $out;
    }

    public static function csvLine(array $fields): string
    {
        return implode(';', array_map(static function ($f) {
            $f = (string) $f;
            return preg_match('/[;"\r\n,]/', $f) ? '"' . str_replace('"', '""', $f) . '"' : $f;
        }, $fields)) . "\r\n";
    }

    /** Wiersze CSV z wykrytym separatorem (; lub ,), UTF-8 także z BOM. */
    public static function csvRows(string $content): array
    {
        $content = preg_replace('/^\x{FEFF}/u', '', str_replace("\r\n", "\n", $content));
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
        }
        $first = strtok($content, "\n") ?: '';
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        while (($r = fgetcsv($fh, null, $sep, '"', '')) !== false) {
            if ($r !== [null]) {
                $rows[] = array_map(static fn ($x) => trim((string) $x), $r);
            }
        }
        fclose($fh);
        return $rows;
    }

    /** Podgląd importu: [nowe, aktualizacje, błędy [nr linii, opis], poprawne wiersze]. */
    public static function csvPreview(string $content): array
    {
        $rows = self::csvRows($content);
        if ($rows !== [] && in_array(mb_strtolower($rows[0][0] ?? ''), ['nazwa', 'name'], true)) {
            array_shift($rows);
            $offset = 2;
        } else {
            $offset = 1;
        }
        $new = $upd = 0;
        $errors = [];
        $valid = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $line = $i + $offset;
            [$name, $number, $groups, $note] = array_pad($r, 4, '');
            $phone = Phone::normalize($number);
            if ($name === '' || $phone === null) {
                $errors[] = [$line, $name === '' ? t('contacts.err_no_name') : t('contacts.err_number', ['number' => $number, 'why' => Phone::error($number)])];
                continue;
            }
            if (isset($seen[$phone])) {
                $errors[] = [$line, t('contacts.err_duplicate', ['line' => $seen[$phone]])];
                continue;
            }
            $seen[$phone] = $line;
            self::byPhone($phone) === null ? $new++ : $upd++;
            $valid[] = ['name' => $name, 'phone' => $phone, 'groups' => array_filter(array_map('trim', explode('|', $groups))), 'note' => $note];
        }
        return ['new' => $new, 'updated' => $upd, 'errors' => $errors, 'rows' => $valid];
    }

    /** Import poprawnych wierszy (dopasowanie po numerze); grupy tworzone w razie potrzeby. */
    public static function csvImport(array $rows): array
    {
        $new = $upd = 0;
        Db::tx(static function () use ($rows, &$new, &$upd): void {
            foreach ($rows as $r) {
                $existing = self::byPhone($r['phone']);
                if ($existing === null) {
                    $id = Db::insert('contacts', ['name' => mb_substr($r['name'], 0, 190), 'phone' => $r['phone'], 'note' => $r['note'],
                        'created_at' => now_db(), 'updated_at' => now_db()]);
                    $new++;
                } else {
                    $id = (int) $existing['id'];
                    Db::update('contacts', ['name' => mb_substr($r['name'], 0, 190), 'note' => $r['note'] !== '' ? $r['note'] : $existing['note'],
                        'updated_at' => now_db()], 'id = ?', [$id]);
                    $upd++;
                }
                foreach ($r['groups'] as $g) {
                    self::addToGroup([$id], (int) self::groupByName($g, true));
                }
            }
        });
        self::forget();
        return [$new, $upd];
    }
}
