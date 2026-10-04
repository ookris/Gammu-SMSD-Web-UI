<?php
declare(strict_types=1);

/**
 * Numery telefonów (rozdz. 3.13): normalizacja do postaci 48601234567, numery skrócone, nadawcy alfanumeryczni,
 * format dla Gammu (+48601234567) i do wyświetlania (+48 601 234 567).
 */
final class Phone
{
    private const SEPARATORS = [' ', '-', '.', '(', ')', '/', "\t", "\u{00A0}"];

    /** Numery kierunkowe krajów o długości 1 i 2 cyfr (pozostałe – 3 cyfry); tylko do ładnego wyświetlania. */
    private const CC1 = ['1', '7'];
    private const CC2 = ['20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49',
        '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86',
        '90', '91', '92', '93', '94', '95', '98'];

    /** Postać znormalizowana albo null, gdy to nie jest poprawny numer. */
    public static function normalize(string $input): ?string
    {
        return self::parse($input)[0];
    }

    /** Powód odrzucenia numeru (do listy błędnych numerów). */
    public static function error(string $input): ?string
    {
        return self::parse($input)[1];
    }

    /** @return array{0:?string,1:?string} [numer, błąd] */
    private static function parse(string $input): array
    {
        $raw = trim($input);
        $digits = str_replace(self::SEPARATORS, '', $raw);
        $national = (int) cfg('national_number_length', 9);
        $cc = (string) cfg('default_country_code', '48');
        if ($digits === '') {
            return [null, t('phone.empty')];
        }
        $international = false;
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
            $international = true;
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }
        if (!ctype_digit($digits)) {
            return [null, t('phone.not_number')];
        }
        $len = strlen($digits);
        if (!$international && $len === $national) {
            $digits = $cc . $digits;
        } elseif (!$international && $len < $national) {
            // Numer skrócony (np. 8080) wpisuje się bez separatorów – „601 23” to raczej ucięty numer
            if ($digits !== $raw || $len < 3) {
                return [null, t('phone.too_short_national', ['n' => $national])];
            }
            return [$digits, null];
        }
        $len = strlen($digits);
        if ($len < 3) {
            return [null, t('phone.too_short')];
        }
        if ($len > 15) {
            return [null, t('phone.too_long')];
        }
        if ($international && $len <= $national) {
            return [null, t('phone.too_short_intl')];
        }
        return [$digits, null];
    }

    /** Nadawca alfanumeryczny (np. ORLEN) – nie da się mu odpowiedzieć. */
    public static function isAlpha(string $phone): bool
    {
        return $phone !== '' && !preg_match('/^\+?[0-9]+$/', $phone);
    }

    /** Numer skrócony (np. 8080) – krótszy niż numer krajowy. */
    public static function isShort(string $phone): bool
    {
        return ctype_digit($phone) && strlen($phone) < (int) cfg('national_number_length', 9);
    }

    /** Postać zapisywana do outbox.DestinationNumber: międzynarodowy z +, skrócony bez zmian. */
    public static function toGammu(string $phone): string
    {
        return self::isAlpha($phone) || self::isShort($phone) ? $phone : '+' . $phone;
    }

    /** Numer z inbox.SenderNumber / sentitems.DestinationNumber → postać panelu. */
    public static function fromGammu(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }
        $clean = str_replace(self::SEPARATORS, '', $number);
        if (!preg_match('/^(\+|00)?[0-9]+$/', $clean)) {
            return mb_substr($number, 0, 32); // nadawca alfanumeryczny
        }
        if (str_starts_with($clean, '+')) {
            return substr($clean, 1);
        }
        if (str_starts_with($clean, '00')) {
            return substr($clean, 2);
        }
        return strlen($clean) === (int) cfg('national_number_length', 9) ? cfg('default_country_code', '48') . $clean : $clean;
    }

    /** Wyświetlanie: +48 601 234 567, +44 7911 123456, 8080, ORLEN. */
    public static function format(string $phone): string
    {
        if ($phone === '') {
            return 'numer ukryty';
        }
        if (self::isAlpha($phone) || self::isShort($phone)) {
            return $phone;
        }
        $ccLen = in_array($phone[0], self::CC1, true) ? 1 : (in_array(substr($phone, 0, 2), self::CC2, true) ? 2 : 3);
        $cc = substr($phone, 0, $ccLen);
        $rest = substr($phone, $ccLen);
        $grouped = strlen($rest) === 9 ? implode(' ', str_split($rest, 3))
            : (strlen($rest) > 6 ? substr($rest, 0, 4) . ' ' . substr($rest, 4) : $rest);
        return '+' . $cc . ' ' . $grouped;
    }

    /** Warianty numeru do pliku czarnej listy – format numeru z modemu nie jest pewny (⚠ U9). */
    public static function variants(string $phone): array
    {
        if (self::isAlpha($phone) || self::isShort($phone)) {
            return [$phone];
        }
        $out = ['+' . $phone, $phone, '00' . $phone];
        $cc = (string) cfg('default_country_code', '48');
        $national = substr($phone, strlen($cc));
        if (str_starts_with($phone, $cc) && strlen($national) === (int) cfg('national_number_length', 9)) {
            $out[] = $national;
        }
        return $out;
    }

    /** Znormalizowany numer lub nazwa nadawcy (blokowanie): numer → postać panelu, nazwa → bez zmian. */
    public static function normalizeSender(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $n = self::normalize($input);
        if ($n !== null) {
            return $n;
        }
        return preg_match('/[A-Za-z]/', $input) && mb_strlen($input) <= 32 ? $input : null;
    }
}
