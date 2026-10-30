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
            // Tryb „x” – plik powstaje tylko raz; przy wyścigu panelu z procesem w tle wygrywa pierwszy, drugi wczytuje jego klucz
            $old = umask(0007);
            $f = @fopen($path, 'x');
            umask($old);
            if ($f !== false) {
                fwrite($f, bin2hex(random_bytes(32)) . "\n");
                fclose($f);
            } else {
                usleep(150000); // drugi proces mógł dopiero co założyć plik i zapisuje klucz
                if (!is_file($path) || trim((string) @file_get_contents($path)) === '') {
                    // Plik istnieje, ale jest pusty (np. po przerwanym zapisie) – nadpisujemy
                    if (@file_put_contents($path, bin2hex(random_bytes(32)) . "\n", LOCK_EX) === false) {
                        throw new RuntimeException(t('file.no_write', ['path' => $path]));
                    }
                }
            }
            $hex = trim((string) @file_get_contents($path));
            if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
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
