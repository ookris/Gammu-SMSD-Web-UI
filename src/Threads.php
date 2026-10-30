<?php
declare(strict_types=1);

/** Rozmowy: lista – jeden wiersz na numer, oś czasu rozmowy z wiadomościami i połączeniami. */
final class Threads
{
    /**
     * Lista rozmów: ostatnia wiadomość na numer (MAX(id) GROUP BY phone) + liczba nieprzeczytanych.
     * Filtry ($f: q, from, to, unread) zawężają listę do numerów z pasującymi wiadomościami.
     */
    public static function list(array $f = [], int $limit = 200): array
    {
        $params = [];
        $where = [];
        $q = (string) ($f['q'] ?? '');
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            $where[] = '(phone LIKE ? OR body LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
            array_push($params, '%' . ($digits !== '' ? ltrim($digits, '0') : $q) . '%', "%$q%", "%$q%");
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = 'COALESCE(received_at, created_at) >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = 'COALESCE(received_at, created_at) <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        if (($f['unread'] ?? '') !== '') {
            $where[] = "direction = 'in' AND is_read = 0";
        }
        $filter = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        // Filtry wybierają numery; wiersz pokazuje prawdziwą ostatnią wiadomość rozmowy
        $rows = Db::all("SELECT m.* FROM messages m JOIN (SELECT phone, MAX(id) AS id FROM messages GROUP BY phone) t ON t.id = m.id
            WHERE m.phone IN (SELECT phone FROM messages $filter) ORDER BY m.id DESC LIMIT $limit", $params);
        $unread = array_column(Db::all("SELECT phone, COUNT(*) AS n FROM messages WHERE direction = 'in' AND is_read = 0 GROUP BY phone"), 'n', 'phone');
        foreach ($rows as &$r) {
            $r['unread'] = (int) ($unread[$r['phone']] ?? 0);
        }
        return $rows;
    }

    /** Oś czasu: wiadomości i odrzucone połączenia z numerem, od najstarszych. */
    public static function timeline(string $phone, int $limit = 200): array
    {
        $msgs = Db::all('SELECT *, COALESCE(received_at, created_at) AS at FROM messages WHERE phone = ? ORDER BY id DESC LIMIT ' . $limit, [$phone]);
        $calls = Db::all("SELECT id, received_at AS at, modem, 'call' AS kind FROM calls WHERE phone = ? ORDER BY received_at DESC, id DESC LIMIT " . $limit, [$phone]);
        $items = array_merge(array_map(static fn ($m) => $m + ['kind' => 'msg'], $msgs), $calls);
        // Od najstarszych; ta sama sekunda: najpierw połączenia, potem wiadomości, każde rosnąco po id
        $key = static fn (array $x) => [$x['at'], $x['kind'] === 'call' ? 0 : 1, (int) $x['id']];
        usort($items, static fn ($a, $b) => $key($a) <=> $key($b));
        return array_slice($items, -$limit); // najnowsze $limit wpisów obu rodzajów
    }

    /** Znacznik wersji rozmowy – auto-odświeżanie pobiera fragment tylko po zmianie. */
    public static function version(string $phone): string
    {
        $m = Db::row('SELECT COUNT(*) AS n, MAX(updated_at) AS u FROM messages WHERE phone = ?', [$phone]);
        $c = Db::val('SELECT COUNT(*) FROM calls WHERE phone = ?', [$phone]);
        return substr(md5($m['n'] . '|' . $m['u'] . '|' . $c), 0, 12);
    }

    public static function markRead(string $phone): void
    {
        Db::exec("UPDATE messages SET is_read = 1, updated_at = NOW() WHERE phone = ? AND direction = 'in' AND is_read = 0", [$phone]);
        Db::exec('UPDATE calls SET is_read = 1 WHERE phone = ? AND is_read = 0', [$phone]);
    }

    /** Oznacza rozmowy jako nieprzeczytane: ostatnia odebrana wiadomość każdego numeru wraca na „nieprzeczytane”. */
    public static function markUnread(array $phones): void
    {
        if ($phones === []) {
            return;
        }
        Db::exec("UPDATE messages SET is_read = 0, updated_at = NOW() WHERE id IN (
            SELECT id FROM (SELECT MAX(id) AS id FROM messages WHERE direction = 'in' AND phone IN (" . Db::in($phones) . ') GROUP BY phone) x)', $phones);
    }

    public static function delete(string $phone): int
    {
        Db::exec('DELETE FROM calls WHERE phone = ?', [$phone]);
        return Db::exec("DELETE FROM messages WHERE phone = ? AND NOT (direction = 'out' AND status IN ('scheduled','queued'))", [$phone]);
    }
}
