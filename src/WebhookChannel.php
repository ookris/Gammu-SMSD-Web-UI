<?php
declare(strict_types=1);

/** Webhook: POST z JSON, opcjonalny podpis HMAC-SHA256 w nagłówku X-Smsgui-Signature. */
final class WebhookChannel implements Channel
{
    /** @param array{url?:string,secret?:string} $config secret – zaszyfrowany (Crypto) */
    public function __construct(private array $config)
    {
    }

    /** Adres http(s) z nazwą hosta; null gdy poprawny, inaczej opis błędu. Adresy loopback i link-local są zablokowane (SSRF), sieć lokalna dozwolona. */
    public static function checkUrl(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '') {
            return t('channels.err_url');
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return t('channels.err_resolve', ['host' => $host]);
        }
        foreach ($ips as $ip) {
            if (self::forbiddenIp($ip)) {
                return t('channels.err_forbidden_ip', ['ip' => $ip]);
            }
        }
        return null;
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
        $secret = Crypto::decrypt((string) ($this->config['secret'] ?? ''));
        if ($secret !== '') {
            $headers[] = 'X-Smsgui-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }
        return [$body, $headers];
    }

    public function send(array $msg): void
    {
        $url = (string) ($this->config['url'] ?? '');
        if (($err = self::checkUrl($url)) !== null) {
            throw new RuntimeException($err);
        }
        [$body, $headers] = $this->request($msg);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => 10, 'follow_location' => 0, 'ignore_errors' => true,
        ]]);
        $stream = @fopen($url, 'r', false, $ctx);
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
