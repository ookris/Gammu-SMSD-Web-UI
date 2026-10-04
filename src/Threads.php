<?php
declare(strict_types=1);

/** Rozmowy: lista – jeden wiersz na numer, oś czasu rozmowy z wiadomościami i połączeniami. */
final class Threads
{
    /** Lista rozmów: ostatnia wiadomość na numer (MAX(id) GROUP BY phone) + liczba nieprzeczytanych. */
    public static function list(string $q = '', int $limit = 200): array
    {
        $params = [];
        $filter = '';
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            $filter = 'WHERE (phone LIKE ? OR body LIKE ? OR phone IN (SELECT phone FROM contacts WHERE name LIKE ?))';
            $params = ['%' . ($digits !== '' ? ltrim($digits, '0') : $q) . '%', "%$q%", "%$q%"];
        }
        $rows = Db::all("SELECT m.* FROM messages m JOIN (SELECT phone, MAX(id) AS id FROM messages $filter GROUP BY phone) t ON t.id = m.id
            ORDER BY m.id DESC LIMIT $limit", $params);
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

    public static function delete(string $phone): int
    {
        Db::exec('DELETE FROM calls WHERE phone = ?', [$phone]);
        return Db::exec("DELETE FROM messages WHERE phone = ? AND NOT (direction = 'out' AND status IN ('scheduled','queued'))", [$phone]);
    }
}
