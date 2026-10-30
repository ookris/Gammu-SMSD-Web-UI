<?php
declare(strict_types=1);

/** Tokeny HTTP API: tworzenie (token widoczny tylko raz), uwierzytelnianie nagłówkiem Bearer, lista dozwolonych adresów IP. */
final class ApiTokens
{
    public static function all(): array
    {
        return Db::all('SELECT * FROM api_tokens ORDER BY revoked_at IS NOT NULL, id DESC');
    }

    /**
     * Nowy token. Zwraca [id, token jawny, błąd]; jawny token nigdzie poza odpowiedzią nie jest zapisywany.
     * @return array{0:?int,1:?string,2:?string}
     */
    public static function create(string $name, string $ips): array
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            return [null, null, t('api.err_name')];
        }
        $list = self::parseIps($ips);
        if ($list === null) {
            return [null, null, t('api.err_ips')];
        }
        $token = 'sg_' . bin2hex(random_bytes(20));
        $id = Db::insert('api_tokens', [
            'name' => $name, 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 8),
            'allowed_ips' => $list === [] ? null : implode(', ', $list), 'created_at' => now_db(),
        ]);
        return [$id, $token, null];
    }

    public static function revoke(int $id): void
    {
        Db::exec('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [now_db(), $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM api_tokens WHERE id = ? AND revoked_at IS NOT NULL', [$id]);
    }

    /** Token z nagłówka → wiersz (z aktualizacją ostatniego użycia) albo kod błędu: 'invalid' | 'ip'. */
    public static function authenticate(string $token, string $ip): array|string
    {
        $row = $token === '' ? null : Db::row('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $token)]);
        if ($row === null) {
            return 'invalid';
        }
        if ($row['allowed_ips'] !== null && !self::ipAllowed($ip, (string) $row['allowed_ips'])) {
            return 'ip';
        }
        Db::update('api_tokens', ['last_used_at' => now_db(), 'last_ip' => mb_substr($ip, 0, 45)], 'id = ?', [$row['id']]);
        return $row;
    }

    /** Lista adresów i sieci CIDR (oddzielone przecinkami lub spacjami); null gdy któryś wpis jest błędny. @return ?list<string> */
    public static function parseIps(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (self::cidr($entry) === null) {
                return null;
            }
            $out[] = $entry;
        }
        return $out;
    }

    public static function ipAllowed(string $ip, string $list): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $c = self::cidr($entry);
            if ($c === null || strlen($c[0]) !== strlen($addr)) {
                continue;
            }
            [$net, $bits] = $c;
            $full = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($addr, 0, $full) !== substr($net, 0, $full)) {
                continue;
            }
            if ($rest === 0 || (ord($addr[$full]) & (0xff << (8 - $rest)) & 0xff) === (ord($net[$full]) & (0xff << (8 - $rest)) & 0xff)) {
                return true;
            }
        }
        return false;
    }

    /** @return ?array{0:string,1:int} adres binarnie i długość prefiksu */
    private static function cidr(string $entry): ?array
    {
        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        if ($bin === false) {
            return null;
        }
        $max = strlen($bin) * 8;
        if ($bits === null) {
            return [$bin, $max];
        }
        return ctype_digit($bits) && (int) $bits <= $max ? [$bin, (int) $bits] : null;
    }
}
