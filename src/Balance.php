<?php
declare(strict_types=1);

/** Cykliczne sprawdzanie salda: USSD co N godzin, odczyt kwoty z odpowiedzi, historia i alarm przy niskim saldzie. */
final class Balance
{
    /** Domyślny wzorzec: kwota przed „zł” / „PLN” („12,34 zł”, „Saldo: 5.20PLN”), a gdy go brak – pierwsza liczba z groszami. */
    private const DEFAULT_PATTERNS = ['/(\d{1,6}(?:[.,]\d{1,2})?)\s*(?:zł|zl|pln)/iu', '/(\d{1,6}[.,]\d{2})(?!\d)/u'];
    /** Najwcześniej po tylu sekundach od poprzedniego zapytania (także nieudanego) próbujemy ponownie. */
    private const RETRY = 900;

    public static function enabled(): bool
    {
        return Settings::int('balance_hours') > 0 && preg_match('/^[0-9*#+]{1,20}$/', (string) Settings::get('balance_code')) === 1;
    }

    /** Kwota z odpowiedzi USSD jako liczba (kropka dziesiętna) albo null. */
    public static function parse(string $response, string $custom = ''): ?float
    {
        if ($custom === '' && preg_match('/(\d{1,6})\s*(?:zł|zl)\s*(\d{1,2})\s*gr\b/iu', $response, $m) === 1) {
            return round((int) $m[1] + (int) $m[2] / 100, 2); // „5 zł 20 gr”
        }
        $patterns = $custom !== '' ? ['/' . str_replace('/', '\\/', $custom) . '/iu'] : self::DEFAULT_PATTERNS;
        foreach ($patterns as $re) {
            if (@preg_match($re, $response, $m) === 1 && isset($m[1])) {
                $num = str_replace([' ', ','], ['', '.'], $m[1]);
                if (is_numeric($num)) {
                    return round((float) $num, 2);
                }
            }
        }
        return null;
    }

    /** Czy wyrażenie z ustawień się kompiluje i ma grupę przechwytującą. */
    public static function validRegex(string $custom): bool
    {
        return $custom === '' || (@preg_match('/' . str_replace('/', '\\/', $custom) . '/iu', '') !== false && substr_count($custom, '(') >= 1);
    }

    /** Krok synchronizacji: zlecenie zapytania, gdy minął odstęp (albo poprzednie zostało bez odpowiedzi), i zapis nadesłanych odpowiedzi. */
    public static function process(): int
    {
        $n = self::record();
        if (!self::enabled()) {
            return $n;
        }
        $last = ts(Settings::get('balance_requested_at'));
        $due = $last === null || time() - $last >= Settings::int('balance_hours') * 3600
            || (time() - $last >= self::RETRY && !self::answeredSince($last));
        $m = Status::modem();
        if ($due && Status::service() === 'active' && Status::modemAvailable($m) && Ussd::pending() === null) {
            Settings::set('balance_requested_at', now_db());
            [, $err] = Ussd::send((string) Settings::get('balance_code'), $m['modem'] ?? null, null, 'balance', false);
            if ($err !== null) {
                app_log('warning', 'saldo: ' . $err);
            }
        }
        return $n;
    }

    private static function answeredSince(int $since): bool
    {
        return Db::val("SELECT 1 FROM ussd_requests WHERE purpose = 'balance' AND status = 'answered' AND created_at >= ? LIMIT 1", [date('Y-m-d H:i:s', $since)]) !== null;
    }

    /** Zapis odpowiedzi na zapytania o saldo, których jeszcze nie ma w historii. */
    public static function record(): int
    {
        $n = 0;
        foreach (Db::all("SELECT r.* FROM ussd_requests r LEFT JOIN balance_log b ON b.ussd_id = r.id
            WHERE r.purpose = 'balance' AND r.status = 'answered' AND b.id IS NULL ORDER BY r.id") as $r) {
            $amount = self::parse((string) $r['response'], (string) Settings::get('balance_regex', ''));
            $at = $r['answered_at'] ?? now_db();
            Db::tx(static function () use ($r, $amount, $at): void {
                Db::insert('balance_log', ['ussd_id' => $r['id'], 'modem' => $r['modem'], 'amount' => $amount, 'raw' => (string) $r['response'], 'created_at' => $at]);
                if ($r['modem'] !== null) {
                    Db::exec('UPDATE modem_status SET balance = ?, balance_value = ?, balance_at = ? WHERE modem = ?',
                        [mb_substr(preg_replace('/\s+/u', ' ', (string) $r['response']), 0, 255), $amount, $at, $r['modem']]);
                }
            });
            $n++;
        }
        return $n;
    }

    /** Ostatni znany odczyt: ['amount' => ?float, 'at' => string] albo null. */
    public static function latest(): ?array
    {
        $r = Db::row('SELECT amount, created_at FROM balance_log ORDER BY id DESC LIMIT 1');
        return $r === null ? null : ['amount' => $r['amount'] === null ? null : (float) $r['amount'], 'at' => $r['created_at']];
    }

    /** Punkty wykresu z ostatnich $days dni: list<array{at:string,amount:float}> (tylko odczyty z rozpoznaną kwotą). */
    public static function series(int $days = 30): array
    {
        return array_map(static fn ($r) => ['at' => $r['created_at'], 'amount' => (float) $r['amount']],
            Db::all('SELECT created_at, amount FROM balance_log WHERE amount IS NOT NULL AND created_at >= ? ORDER BY created_at', [date('Y-m-d H:i:s', time() - $days * 86400)]));
    }
}
