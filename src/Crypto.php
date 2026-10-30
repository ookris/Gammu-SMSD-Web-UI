<?php
declare(strict_types=1);

/** Szyfrowanie sekretów zapisywanych w bazie (hasło SMTP, sekret webhooka): AES-256-GCM, klucz w pliku obok logu panelu. */
final class Crypto
{
    private const PREFIX = 'enc1:';
    private static ?string $key = null;

    public static function keyPath(): string
    {
        return (string) (cfg('secret_file') ?: dirname((string) cfg('log_path')) . '/secret.key');
    }

    /** Klucz 32 bajty; plik powstaje przy pierwszym użyciu (prawa 0660 – panel i proces w tle działają na jednym koncie lub w jednej grupie). */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $path = self::keyPath();
        $hex = is_file($path) ? trim((string) @file_get_contents($path)) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            $hex = bin2hex(random_bytes(32));
            $old = umask(0007);
            $ok = @file_put_contents($path, $hex . "\n", LOCK_EX);
            umask($old);
            if ($ok === false) {
                throw new RuntimeException(t('file.no_write', ['path' => $path]));
            }
        }
        return self::$key = hex2bin($hex);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('openssl_encrypt');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Odszyfrowanie; wartość bez prefiksu albo uszkodzona daje pusty tekst. */
    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }
}
