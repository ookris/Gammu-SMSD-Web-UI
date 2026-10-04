<?php
declare(strict_types=1);

/** Czarna lista numerów: tabela blocked_numbers → plik ExcludeNumbersFile dla Gammu. */
final class Blocklist
{
    public static function path(): string
    {
        return (string) cfg('blocklist_file');
    }

    /** Treść pliku: każdy numer w kilku wariantach, posortowane, bez powtórzeń. */
    public static function fileContent(): string
    {
        $lines = [];
        foreach (Db::col('SELECT phone FROM blocked_numbers ORDER BY phone') as $phone) {
            array_push($lines, ...Phone::variants((string) $phone));
        }
        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /** Zapis atomowy (plik tymczasowy + rename) z kopią poprzedniej wersji. */
    public static function write(): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new RuntimeException(t('file.no_dir', ['dir' => $dir]));
        }
        if (is_file($path)) {
            $old = (string) @file_get_contents($path);
            if ($old === self::fileContent()) {
                return;
            }
            GammuConf::backup($old, msg_key('blocklist.backup_note'), 'exclude-numbers.txt');
        }
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, self::fileContent()) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(t('file.cannot_write', ['path' => $path]));
        }
        @chmod($path, 0644);
        Settings::set('blocklist_written_at', now_db());
    }

    public static function inSync(): bool
    {
        $text = @file_get_contents(self::path());
        return ($text === false ? '' : $text) === self::fileContent();
    }

    public static function isBlocked(string $phone): bool
    {
        return $phone !== '' && Db::val('SELECT 1 FROM blocked_numbers WHERE phone = ?', [$phone]) !== null;
    }

    /** Zbiór zablokowanych numerów z listy (jedno zapytanie). */
    public static function blockedAmong(array $phones): array
    {
        if ($phones === []) {
            return [];
        }
        return Db::col('SELECT phone FROM blocked_numbers WHERE phone IN (' . Db::in($phones) . ')', array_values($phones));
    }

    public static function enabledInConf(): bool
    {
        $v = GammuConf::load()?->get('smsd', 'excludenumbersfile');
        return $v !== null && $v === self::path();
    }

    /** Dodanie numeru lub nazwy; [numer, błąd]. */
    public static function add(string $input, string $note = ''): array
    {
        $phone = Phone::normalizeSender($input);
        if ($phone === null) {
            return [null, t('blocklist.not_sender', ['input' => $input])];
        }
        if (self::isBlocked($phone)) {
            return [$phone, null];
        }
        Db::insert('blocked_numbers', ['phone' => $phone, 'note' => mb_substr($note, 0, 255), 'created_at' => now_db()]);
        return [$phone, null];
    }

    /** Zapis pliku i przeładowanie Gammu po zmianie listy; komunikat o wyniku. */
    public static function apply(): string
    {
        self::write();
        if (!self::enabledInConf()) {
            return t('blocklist.saved_disabled');
        }
        [$code, $out] = Service::reload();
        Settings::set('blocklist_reload', $code === 0 ? 'ok' : $out);
        return $code === 0 ? t('blocklist.reloaded') . '.' : t('blocklist.reload_failed', ['out' => $out]);
    }

    /** Blokada z rozmowy, odebranych, kontaktu lub połączeń – komunikat flash. */
    public static function blockFromPanel(string $phone, string $note = ''): void
    {
        [$p, $err] = self::add($phone, $note);
        if ($err !== null) {
            flash('err', t('blocklist.not_blocked'), $err);
            return;
        }
        try {
            flash('ok', t('blocklist.blocked', ['who' => Phone::format((string) $p)]), self::apply());
        } catch (Throwable $e) {
            flash('warn', t('blocklist.not_written'), $e->getMessage());
        }
    }
}
