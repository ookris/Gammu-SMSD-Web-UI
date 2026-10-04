<?php
declare(strict_types=1);

/** Logowanie do panelu – jedno konto (D1), blokada po 5 próbach, wygasanie sesji, „Zapamiętaj mnie” (rozdz. 2.1, 5.1). */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const REMEMBER_DAYS = 30;
    private const COOKIE = 'smsgui_remember';

    private static ?array $user = null;

    public static function https(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    }

    public static function startSession(): void
    {
        $path = (string) cfg('session_path');
        if ($path !== '') {
            if (!is_dir($path)) {
                @mkdir($path, 0700, true);
            }
            if (is_dir($path) && is_writable($path)) {
                session_save_path($path);
            }
        }
        ini_set('session.gc_maxlifetime', (string) (max(1, Settings::int('session_hours')) * 3600 + 600));
        ini_set('session.use_strict_mode', '1');
        session_name('smsgui');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Zalogowany użytkownik albo null (sprawdza wygaśnięcie i ciasteczko „Zapamiętaj mnie”). */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        $idle = max(1, Settings::int('session_hours')) * 3600;
        if ($uid > 0 && time() - (int) ($_SESSION['last_seen'] ?? 0) > $idle) {
            self::endSession();
            $_SESSION['expired'] = true;
            $uid = 0;
        }
        if ($uid === 0) {
            $uid = self::fromRememberCookie();
            if ($uid > 0) {
                self::loginSession($uid);
            }
        }
        if ($uid === 0) {
            return null;
        }
        self::$user = Db::row('SELECT id, username, remember_secret FROM users WHERE id = ?', [$uid]);
        if (self::$user === null) {
            self::endSession();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return self::$user;
    }

    /** Sekundy do końca blokady dla adresu IP (0 = brak blokady). */
    public static function lockedFor(string $ip): int
    {
        Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [now_db(-self::LOCK_MINUTES * 60)]);
        $rows = Db::col('SELECT attempted_at FROM login_attempts WHERE ip = ? ORDER BY attempted_at', [$ip]);
        if (count($rows) < self::MAX_ATTEMPTS) {
            return 0;
        }
        return max(1, (int) ts($rows[count($rows) - self::MAX_ATTEMPTS]) + self::LOCK_MINUTES * 60 - time());
    }

    /** Próba logowania; null = sukces, inaczej komunikat błędu. */
    public static function attempt(string $username, string $password, bool $remember): ?string
    {
        $ip = request_ip();
        $locked = self::lockedFor($ip);
        if ($locked > 0) {
            return t('auth.locked', ['min' => (int) ceil($locked / 60)]);
        }
        $user = Db::row('SELECT id, password_hash FROM users WHERE username = ?', [$username]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            Db::insert('login_attempts', ['ip' => $ip, 'attempted_at' => now_db()]);
            // Format dla fail2ban (deploy/fail2ban): stała fraza + adres
            app_log('auth', 'failed login for user "' . mb_substr($username, 0, 64) . '" from ' . $ip);
            $left = self::MAX_ATTEMPTS - (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip]);
            return $left > 0 ? t('auth.bad') . ' ' . t('auth.left', ['n' => $left])
                : t('auth.locked', ['min' => self::LOCK_MINUTES]);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        Db::exec('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        Db::update('users', ['last_login_at' => now_db()], 'id = ?', [$user['id']]);
        self::loginSession((int) $user['id']);
        if ($remember) {
            self::setRememberCookie((int) $user['id']);
        }
        app_log('auth', 'login ok from ' . $ip);
        return null;
    }

    public static function logout(): void
    {
        self::endSession();
        self::clearRememberCookie();
    }

    /** Ustawienie loginu i hasła jedynego konta (CLI passwd, ekran zmiany hasła). */
    public static function setCredentials(string $username, string $password): void
    {
        $data = [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'remember_secret' => bin2hex(random_bytes(32)), // unieważnia wszystkie ciasteczka „Zapamiętaj mnie”
        ];
        $id = Db::val('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($id === null) {
            Db::insert('users', $data + ['created_at' => now_db()]);
        } else {
            Db::update('users', $data, 'id = ?', [$id]);
        }
    }

    /** Zmiana hasła (i loginu) z panelu; null = sukces, inaczej komunikat błędu. */
    public static function changePassword(string $username, string $current, string $new, string $repeat): ?string
    {
        $user = self::user() ?? throw new LogicException('Brak zalogowanego użytkownika');
        $hash = (string) Db::val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        return match (true) {
            $username === '' => t('password.login_empty'),
            !password_verify($current, $hash) => t('password.current_bad'),
            mb_strlen($new) < 10 => t('password.too_short'),
            $new !== $repeat => t('password.mismatch'),
            default => (function () use ($username, $new, $user) {
                self::setCredentials($username, $new);
                self::$user = null;
                session_regenerate_id(true);
                if (isset($_COOKIE[self::COOKIE])) {
                    self::setRememberCookie((int) $user['id']);
                }
                return null;
            })(),
        };
    }

    private static function loginSession(int $uid): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['last_seen'] = time();
        unset($_SESSION['expired']);
        self::$user = null;
    }

    private static function endSession(): void
    {
        unset($_SESSION['uid'], $_SESSION['last_seen']);
        self::$user = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function setRememberCookie(int $uid): void
    {
        $secret = (string) Db::val('SELECT remember_secret FROM users WHERE id = ?', [$uid]);
        $expires = time() + self::REMEMBER_DAYS * 86400;
        $payload = $uid . '.' . $expires;
        setcookie(self::COOKIE, $payload . '.' . hash_hmac('sha256', $payload, $secret), [
            'expires' => $expires, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::COOKIE])) {
            setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    private static function fromRememberCookie(): int
    {
        $parts = explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''));
        if (count($parts) !== 3 || (int) $parts[1] < time()) {
            return 0;
        }
        [$uid, $expires, $mac] = $parts;
        $secret = Db::val('SELECT remember_secret FROM users WHERE id = ?', [(int) $uid]);
        if ($secret === null || !hash_equals(hash_hmac('sha256', $uid . '.' . $expires, (string) $secret), $mac)) {
            return 0;
        }
        return (int) $uid;
    }
}
