<?php
declare(strict_types=1);

/** Usługa Gammu SMSD: stan, przeładowanie, restart (polecenia tylko z config.php – nie da się ich zmienić z WWW) i odczyt logu. */
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

    /** Restart z czekaniem na modem: wynik w sesji, zakładka „Usługa” odpytuje phones do 30 s. */
    public static function restartAndWatch(string $note = ''): string
    {
        $started = now_db();
        [$code, $out] = self::restart();
        $_SESSION['service_op'] = ['cmd' => (string) cfg('service.restart_cmd'), 'code' => $code, 'out' => $out, 'started' => $started,
            'wait' => true, 'note' => $note];
        flash($code === 0 ? 'ok' : 'err', t($code === 0 ? 'config.restarted' : 'config.restart_failed'), $note);
        return url('config', ['tab' => 'service', 'wait' => 1]);
    }

    /** Czy modem zgłosił się w phones po $since (restart). */
    public static function modemBack(string $since): ?array
    {
        return Db::row('SELECT ID, UpdatedInDB FROM {g}.phones WHERE UpdatedInDB >= ? ORDER BY UpdatedInDB DESC LIMIT 1', [$since]);
    }
}
