<?php
declare(strict_types=1);

/**
 * Proste HTTP API (/api/…) dla skryptów i systemów monitoringu; uwierzytelnianie tokenem Bearer, bez sesji i CSRF.
 *   POST /api/send   to, text, report, flash, translit, send_at, priority (normal|high), valid_for (minuty)
 *   GET  /api/status ?id=<id z odpowiedzi /api/send>
 */
final class Api
{
    /** Po tylu wiadomościach z jednego tokenu w ciągu minuty kolejne żądania dostają 429 (pojedyncze żądanie może mieć do max_recipients odbiorców). */
    public const PER_MINUTE = 120;
    private const MAX_BODY = 1048576;

    public static function handle(string $route): never
    {
        header('Content-Type: application/json; charset=utf-8');
        $token = self::bearer();
        $auth = ApiTokens::authenticate($token, request_ip());
        if (!is_array($auth)) {
            if ($token !== '') {
                // Format dla fail2ban (deploy/fail2ban) – jak przy nieudanym logowaniu; brak tokenu to nie próba logowania
                app_log('auth', 'failed login for user "api" from ' . request_ip());
                usleep(300000);
            }
            self::reply($auth === 'ip' ? 403 : 401, ['ok' => false, 'error' => t($auth === 'ip' ? 'api.e_ip' : 'api.e_token')]);
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        try {
            match ($route) {
                'send' => $method === 'POST' ? self::send($auth) : self::reply(405, ['ok' => false, 'error' => t('api.e_method')]),
                'status' => $method === 'GET' ? self::status($auth) : self::reply(405, ['ok' => false, 'error' => t('api.e_method')]),
                default => self::reply(404, ['ok' => false, 'error' => t('api.e_route')]),
            };
        } catch (Throwable $e) {
            // Klient API oczekuje JSON, nie strony błędu HTML
            app_log('error', 'API ' . $route . ': ' . $e::class . ': ' . $e->getMessage());
            self::reply(500, ['ok' => false, 'error' => t('api.e_internal')]);
        }
    }

    private static function bearer(): string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', trim((string) $h), $m) ? $m[1] : '';
    }

    private static function reply(int $code, array $body): never
    {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /** Dane żądania: JSON (Content-Type: application/json) albo formularz. */
    private static function input(): array
    {
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY);
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                self::reply(400, ['ok' => false, 'error' => t('api.e_json')]);
            }
            return $d;
        }
        return $_POST;
    }

    private static function flag(mixed $v, bool $default): bool
    {
        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function send(array $token): never
    {
        $d = self::input();
        $to = $d['to'] ?? '';
        $to = is_array($to) ? implode(',', array_filter($to, 'is_scalar')) : (is_scalar($to) ? (string) $to : '');
        $text = $d['text'] ?? '';
        if (!is_string($text)) {
            self::reply(422, ['ok' => false, 'errors' => [t('compose.err_text')]]);
        }
        $in = [
            'groups' => [], 'contacts' => [], 'numbers' => $to, 'text' => SmsText::normalize($text),
            'translit' => self::flag($d['translit'] ?? null, Settings::bool('translit_default')),
            'report' => self::flag($d['report'] ?? null, Settings::bool('report_default')),
            'flash' => self::flag($d['flash'] ?? null, false),
            'priority' => ($d['priority'] ?? 'normal') === 'high',
            'send_at' => is_scalar($d['send_at'] ?? null) ? (string) $d['send_at'] : '',
            'validity' => Outbox::validity($d['valid_for'] ?? Settings::get('validity_default')),
            'modem' => Status::validModem($d['modem'] ?? null),
            'skip_window' => false, 'template' => '', 'source' => 'api', 'api_token_id' => (int) $token['id'],
        ];
        if (isset($d['modem']) && $d['modem'] !== '' && $in['modem'] === null) {
            self::reply(422, ['ok' => false, 'errors' => [t('api.e_modem', ['modem' => is_scalar($d['modem']) ? (string) $d['modem'] : '?'])]]);
        }
        $plan = Compose::plan($in);
        if ($plan['errors'] !== []) {
            $errors = $plan['errors'];
            foreach ($plan['recipients']['invalid'] as [$number, $why]) {
                $errors[] = "$number: $why";
            }
            self::reply(422, ['ok' => false, 'errors' => $errors]);
        }
        $recent = (int) Db::val('SELECT COUNT(*) FROM messages WHERE api_token_id = ? AND created_at > ?', [$token['id'], now_db(-60)]);
        if ($recent >= self::PER_MINUTE) {
            header('Retry-After: 60');
            self::reply(429, ['ok' => false, 'error' => t('api.e_rate', ['n' => self::PER_MINUTE])]);
        }
        [$batch, , $ids] = Compose::send($in, $plan);
        self::reply(200, ['ok' => true, 'count' => count($ids), 'ids' => $ids, 'parts' => $plan['parts'], 'batch' => $batch,
            'start' => $plan['delayed'] ? date('c', $plan['start']) : null]);
    }

    private static function status(array $token): never
    {
        $m = Db::row("SELECT id, phone, status, parts, error, status_code, scheduled_at, sent_at, delivered_at, created_at FROM messages
            WHERE id = ? AND direction = 'out' AND api_token_id = ?", [(int) ($_GET['id'] ?? 0), $token['id']]);
        if ($m === null) {
            self::reply(404, ['ok' => false, 'error' => t('api.e_notfound')]);
        }
        $iso = static fn (?string $dt) => $dt === null ? null : date('c', (int) ts($dt));
        self::reply(200, ['ok' => true, 'id' => (int) $m['id'], 'to' => '+' . $m['phone'], 'status' => $m['status'], 'parts' => (int) $m['parts'],
            'error' => $m['error'] ? tr($m['error']) : null, 'created_at' => $iso($m['created_at']), 'scheduled_at' => $iso($m['scheduled_at']),
            'sent_at' => $iso($m['sent_at']), 'delivered_at' => $iso($m['delivered_at'])]);
    }
}
