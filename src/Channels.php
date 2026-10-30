<?php
declare(strict_types=1);

/** Kanały powiadomień (tabela channels) i ustawienia poczty: zapis, odczyt, tworzenie obiektów Channel, test. */
final class Channels
{
    public const TYPES = ['email', 'webhook', 'sms'];

    public static function all(): array
    {
        return Db::all('SELECT * FROM channels ORDER BY name, id');
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM channels WHERE id = ?', [$id]);
    }

    public static function config(array $row): array
    {
        $c = json_decode((string) $row['config'], true);
        return is_array($c) ? $c : [];
    }

    /** Podmiana fabryki kanałów w testach: fn(array $row): ?Channel. */
    public static ?\Closure $factory = null;

    public static function make(array $row): Channel
    {
        if (self::$factory !== null && ($custom = (self::$factory)($row)) !== null) {
            return $custom;
        }
        $c = self::config($row);
        return match ($row['type']) {
            'email' => new MailChannel($c),
            'webhook' => new WebhookChannel($c),
            'sms' => new SmsChannel($c),
            default => throw new InvalidArgumentException('channel type ' . $row['type']),
        };
    }

    /**
     * Zapis kanału z formularza. Zwraca [id|null, błąd|null]. Pusty sekret przy edycji zostawia dotychczasowy (chyba że secret_clear).
     * @param array{name:string,to?:string,number?:string,url?:string,secret?:string,secret_clear?:bool} $in
     */
    public static function save(?int $id, string $type, array $in): array
    {
        $name = mb_substr(trim($in['name']), 0, 120);
        $old = $id !== null ? self::find($id) : null;
        if ($name === '') {
            return [null, t('channels.err_name')];
        }
        if ($id !== null && ($old === null || $old['type'] !== $type)) {
            return [null, t('channels.err_missing')];
        }
        if ($type === 'email') {
            $to = MailChannel::recipients($in['to'] ?? '');
            if ($to === []) {
                return [null, t('channels.err_no_recipient')];
            }
            $config = ['to' => implode(', ', $to)];
        } elseif ($type === 'webhook') {
            $url = trim($in['url'] ?? '');
            $p = parse_url($url);
            if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '' || mb_strlen($url) > 500) {
                return [null, t('channels.err_url')];
            }
            if (filter_var(trim($p['host'], '[]'), FILTER_VALIDATE_IP) && ($bad = WebhookChannel::checkUrl($url)) !== null) {
                return [null, $bad]; // adres IP zablokowany (loopback, link-local) – od razu przy zapisie
            }
            $secret = $old ? (self::config($old)['secret'] ?? '') : '';
            if (!empty($in['secret_clear'])) {
                $secret = '';
            }
            if (($in['secret'] ?? '') !== '') {
                $secret = Crypto::encrypt($in['secret']);
            }
            $config = ['url' => $url, 'secret' => $secret];
        } elseif ($type === 'sms') {
            $phone = Phone::normalize($in['number'] ?? '');
            if ($phone === null) {
                return [null, t('channels.err_number')];
            }
            $config = ['number' => $phone];
        } else {
            return [null, t('channels.err_missing')];
        }
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($id === null) {
            return [Db::insert('channels', ['type' => $type, 'name' => $name, 'config' => $json, 'enabled' => 1, 'created_at' => now_db()]), null];
        }
        Db::update('channels', ['name' => $name, 'config' => $json], 'id = ?', [$id]);
        return [$id, null];
    }

    public static function setEnabled(int $id, bool $on): void
    {
        Db::update('channels', ['enabled' => (int) $on], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM channels WHERE id = ?', [$id]);
    }

    /** Wysyła wiadomość próbną od razu (bez kolejki); zwraca opis błędu albo null. */
    public static function test(int $id): ?string
    {
        $row = self::find($id);
        if ($row === null) {
            return t('channels.err_missing');
        }
        try {
            self::make($row)->send([
                'subject' => t('channels.test_subject'),
                'text' => t('channels.test_text', ['time' => date('Y-m-d H:i:s')]),
                'data' => ['type' => 'test'],
            ]);
            Db::update('channels', ['last_ok_at' => now_db(), 'last_error' => null], 'id = ?', [$id]);
            return null;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 250);
            Db::update('channels', ['last_error' => $msg], 'id = ?', [$id]);
            return $msg;
        }
    }

    // ---------- Ustawienia poczty ----------

    /** @return array{mode:string,host:string,port:string,secure:string,user:string,pass:string,from:string,from_name:string} hasło odszyfrowane */
    public static function mailSettings(): array
    {
        $s = Settings::json('mail') + ['mode' => 'sendmail', 'host' => '', 'port' => '587', 'secure' => 'tls', 'user' => '', 'pass' => '', 'from' => '', 'from_name' => ''];
        $s['pass'] = Crypto::decrypt((string) $s['pass']);
        return $s;
    }

    /** Zapis ustawień poczty; puste hasło zostawia dotychczasowe. Zwraca błąd lub null. */
    public static function saveMail(array $in): ?string
    {
        $mode = ($in['mode'] ?? '') === 'smtp' ? 'smtp' : 'sendmail';
        $host = trim((string) ($in['host'] ?? ''));
        $port = (string) ($in['port'] ?? '');
        $from = trim((string) ($in['from'] ?? ''));
        if ($mode === 'smtp' && ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host))) {
            return t('channels.err_smtp_host');
        }
        if ($mode === 'smtp' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            return t('channels.err_smtp_port');
        }
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return t('channels.err_from');
        }
        $old = Settings::json('mail');
        $pass = ($in['pass'] ?? '') !== '' ? Crypto::encrypt((string) $in['pass']) : (!empty($in['pass_clear']) ? '' : (string) ($old['pass'] ?? ''));
        Settings::set('mail', [
            'mode' => $mode, 'host' => $host, 'port' => $port ?: '587',
            'secure' => in_array($in['secure'] ?? 'tls', ['', 'tls', 'ssl'], true) ? (string) ($in['secure'] ?? 'tls') : 'tls',
            'user' => mb_substr(trim((string) ($in['user'] ?? '')), 0, 190), 'pass' => $pass,
            'from' => $from, 'from_name' => mb_substr(trim((string) ($in['from_name'] ?? '')), 0, 120),
        ]);
        return null;
    }
}
