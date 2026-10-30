<?php
declare(strict_types=1);

/** Webhook: POST z JSON, opcjonalny podpis HMAC-SHA256 w nagłówku X-Smsgui-Signature. */
final class WebhookChannel implements Channel
{
    /** @param array{url?:string,secret?:string} $config secret – zaszyfrowany (Crypto) */
    public function __construct(private array $config)
    {
    }

    /** Adresy IP hosta (A i AAAA); lista pusta, gdy nazwy nie da się rozwiązać. @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
        return $ips !== [] ? $ips : (@gethostbynamel($host) ?: []);
    }

    /**
     * Sprawdza adres i wybiera IP, z którym połączymy się bezpośrednio (nazwa jest rozwiązywana raz – DNS rebinding nie pomoże).
     * @return array{0:?string,1:?string} [błąd, adres IP]
     */
    private static function target(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '') {
            return [t('channels.err_url'), null];
        }
        $host = trim($p['host'], '[]');
        $ips = self::resolve($host);
        if ($ips === []) {
            return [t('channels.err_resolve', ['host' => $host]), null];
        }
        foreach ($ips as $ip) {
            if (self::forbiddenIp($ip)) {
                return [t('channels.err_forbidden_ip', ['ip' => $ip]), null];
            }
        }
        return [null, $ips[0]];
    }

    /** Adres http(s) z nazwą hosta; null gdy poprawny, inaczej opis błędu. Adresy loopback i link-local są zablokowane (SSRF), sieć lokalna dozwolona. */
    public static function checkUrl(string $url): ?string
    {
        return self::target($url)[0];
    }

    private static function forbiddenIp(string $ip): bool
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) { // ::ffff:a.b.c.d
            $ip = inet_ntop(substr($bin, 12)) ?: $ip;
            $bin = inet_pton($ip);
        }
        if (strlen($bin) === 4) {
            $o = ord($bin[0]);
            return $o === 127 || $o === 0 || ($o === 169 && ord($bin[1]) === 254) || $o >= 224;
        }
        return $bin === str_repeat("\0", 15) . "\1" || $bin === str_repeat("\0", 16) || (ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80);
    }

    /** Treść JSON i nagłówki żądania (z podpisem, jeśli kanał ma sekret). @return array{0:string,1:list<string>} */
    public function request(array $msg): array
    {
        $body = json_encode($msg['data'] + ['text' => $msg['text']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json; charset=utf-8', 'User-Agent: smsgui', 'Content-Length: ' . strlen($body)];
        $stored = (string) ($this->config['secret'] ?? '');
        $secret = Crypto::decrypt($stored);
        if ($stored !== '' && $secret === '') {
            // Nie wysyłamy bez podpisu, gdy sekret jest zapisany, ale nie da się go odczytać (zmieniony lub utracony klucz szyfrowania)
            throw new RuntimeException(t('channels.err_secret'));
        }
        if ($secret !== '') {
            $headers[] = 'X-Smsgui-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }
        return [$body, $headers];
    }

    public function send(array $msg): void
    {
        $url = (string) ($this->config['url'] ?? '');
        [$err, $ip] = self::target($url);
        if ($err !== null) {
            throw new RuntimeException($err);
        }
        [$body, $headers] = $this->request($msg);
        $p = parse_url($url);
        $host = trim($p['host'], '[]');
        $hostPort = (str_contains($host, ':') ? "[$host]" : $host) . (isset($p['port']) ? ':' . $p['port'] : '');
        // Połączenie z już sprawdzonym adresem IP; nazwa trafia do nagłówka Host i do SNI/weryfikacji certyfikatu
        $target = $p['scheme'] . '://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . (isset($p['port']) ? ':' . $p['port'] : '')
            . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $headers[] = 'Host: ' . $hostPort;
        if (isset($p['user'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode(rawurldecode($p['user']) . ':' . rawurldecode($p['pass'] ?? ''));
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
                'timeout' => 10, 'follow_location' => 0, 'ignore_errors' => true],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'SNI_server_name' => $host],
        ]);
        $stream = @fopen($target, 'r', false, $ctx);
        if ($stream === false) {
            throw new RuntimeException(t('channels.err_connect'));
        }
        $meta = stream_get_meta_data($stream);
        fread($stream, 4096); // odpowiedź nieistotna – czytamy tylko tyle, by nie wisieć na dużej treści
        fclose($stream);
        $code = 0;
        foreach ($meta['wrapper_data'] ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1]; // ostatni nagłówek statusu
            }
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(t('channels.err_http', ['code' => $code]));
        }
    }
}
