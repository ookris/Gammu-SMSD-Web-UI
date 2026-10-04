<?php
declare(strict_types=1);

/** Usługa Gammu SMSD: stan, przeładowanie, restart (polecenia tylko z config.php – rozdz. 5.1) i odczyt logu. */
final class Service
{
    /** Uruchomienie polecenia z konfiguracji; [kod wyjścia, wyjście]. */
    public static function run(string $cmd): array
    {
        if (trim($cmd) === '') {
            return [127, 'Brak polecenia w konfiguracji'];
        }
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, trim(implode("\n", $out))];
    }

    /** Odczyt stanu (systemctl is-active) i zapis w settings – wskaźnik w menu nie uruchamia polecenia przy każdej stronie. */
    public static function refreshStatus(): string
    {
        [, $out] = self::run((string) cfg('service.status_cmd'));
        $status = strtok($out, "\n") ?: 'unknown';
        $status = preg_match('/^[a-z-]{2,20}$/', $status) ? $status : 'unknown';
        Settings::set('service_status', $status);
        Settings::set('service_checked_at', now_db());
        return $status;
    }

    public static function reload(): array
    {
        $r = self::run((string) cfg('service.reload_cmd'));
        app_log('info', 'przeładowanie Gammu: kod ' . $r[0]);
        self::refreshStatus();
        return $r;
    }

    public static function restart(): array
    {
        $r = self::run((string) cfg('service.restart_cmd'));
        app_log('info', 'restart Gammu: kod ' . $r[0]);
        self::refreshStatus();
        return $r;
    }

    /** Ścieżka logu Gammu: gammu_log z config.php albo logfile z gammu-smsdrc. */
    public static function logPath(): ?string
    {
        $path = cfg('gammu_log') ?? GammuConf::load()?->get('smsd', 'logfile');
        return $path === null || $path === '' ? null : (string) $path;
    }

    /** Ostatnie $n linii pliku czytane od końca (duży log nie spowalnia panelu). */
    public static function tail(string $path, int $n): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        fseek($fh, 0, SEEK_END);
        $pos = ftell($fh);
        $buffer = '';
        $chunk = 8192;
        while ($pos > 0 && substr_count($buffer, "\n") <= $n) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $buffer = fread($fh, $read) . $buffer;
        }
        fclose($fh);
        $lines = explode("\n", rtrim($buffer, "\n"));
        $lines = array_slice($lines, -$n);
        return array_map(static fn ($l) => mb_scrub(rtrim($l, "\r"), 'UTF-8'), $lines);
    }
}
