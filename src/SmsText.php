<?php
declare(strict_types=1);

/**
 * Kodowanie i liczenie części SMS (rozdz. 3.3, decyzja D13). Ta sama logika jest w public/assets/sms-text.js;
 * zgodność obu wersji sprawdza wspólny plik tests/cases/smstext.json.
 * Alfabet GSM wg Gammu (GSM_DefaultAlphabetUnicode + rozszerzenie): podstawowy z „¤”, bez „¹” i bez znaku nowej strony.
 */
final class SmsText
{
    public const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡"
        . 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    public const GSM_EXT = '^{}\\[~]|€';
    public const MAX_PARTS = 10;

    public const TRANSLIT = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        '„' => '"', '”' => '"', '“' => '"', '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ',
    ];

    public const CODING_GSM = 'Default_No_Compression';
    public const CODING_UCS2 = 'Unicode_No_Compression';

    private static ?array $basic = null;
    private static ?array $ext = null;

    public static function normalize(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    public static function translit(string $text): string
    {
        return strtr($text, self::TRANSLIT);
    }

    /** 1 – znak podstawowy GSM, 2 – rozszerzony (ESC + znak), 0 – spoza alfabetu. */
    public static function gsmUnits(string $ch): int
    {
        self::$basic ??= array_flip(mb_str_split(self::GSM_BASIC));
        self::$ext ??= array_flip(mb_str_split(self::GSM_EXT));
        return isset(self::$basic[$ch]) ? 1 : (isset(self::$ext[$ch]) ? 2 : 0);
    }

    /** Jednostki UTF-16 znaku: 2 dla znaków spoza BMP (emoji), inaczej 1. */
    public static function ucs2Units(string $ch): int
    {
        return mb_ord($ch, 'UTF-8') > 0xFFFF ? 2 : 1;
    }

    /**
     * @return array{gsm:bool,units:int,parts:int,bad:list<string>,chars:int,too_long:bool,coding:string}
     */
    public static function analyze(string $text): array
    {
        $gsm = true;
        $gsmUnits = 0;
        $ucsUnits = 0;
        $bad = [];
        $chars = $text === '' ? [] : mb_str_split($text);
        foreach ($chars as $ch) {
            $u = self::gsmUnits($ch);
            $ucsUnits += self::ucs2Units($ch);
            if ($u === 0) {
                $gsm = false;
                if (!in_array($ch, $bad, true)) {
                    $bad[] = $ch;
                }
            }
            $gsmUnits += $u;
        }
        $units = $gsm ? $gsmUnits : $ucsUnits;
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];
        $parts = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));
        return [
            'gsm' => $gsm, 'units' => $units, 'parts' => $parts, 'bad' => $bad, 'chars' => count($chars),
            'too_long' => $parts > self::MAX_PARTS, 'coding' => $gsm ? self::CODING_GSM : self::CODING_UCS2,
        ];
    }

    /** Opis do licznika: „182 znaki · 2 SMS · GSM-7”. */
    public static function counter(string $text): string
    {
        $a = self::analyze($text);
        return t('sms.counter', ['chars' => tn('sms.chars', $a['chars']), 'parts' => $a['parts'], 'coding' => $a['gsm'] ? 'GSM-7' : 'Unicode']);
    }

    /**
     * Podział na części z nagłówkiem UDH (8-bitowy numer referencyjny): 050003 RR NN PP.
     * Znak rozszerzony GSM i para zastępcza UTF-16 (emoji) nie są rozdzielane między części.
     * @return list<array{text:string,udh:string}>
     */
    public static function split(string $text, int $ref): array
    {
        $a = self::analyze($text);
        if ($a['too_long']) {
            throw new InvalidArgumentException(t('sms.too_long', ['parts' => $a['parts'], 'max' => self::MAX_PARTS]));
        }
        if ($a['parts'] <= 1) {
            return [['text' => $text, 'udh' => '']];
        }
        $limit = $a['gsm'] ? 153 : 67;
        $chunks = [];
        $current = '';
        $used = 0;
        foreach (mb_str_split($text) as $ch) {
            $u = $a['gsm'] ? self::gsmUnits($ch) : self::ucs2Units($ch);
            if ($used + $u > $limit) {
                $chunks[] = $current;
                $current = '';
                $used = 0;
            }
            $current .= $ch;
            $used += $u;
        }
        $chunks[] = $current;
        $n = count($chunks);
        if ($n > self::MAX_PARTS) {
            throw new InvalidArgumentException(t('sms.too_long', ['parts' => $n, 'max' => self::MAX_PARTS]));
        }
        $out = [];
        foreach ($chunks as $i => $chunk) {
            $out[] = ['text' => $chunk, 'udh' => sprintf('050003%02X%02X%02X', $ref & 0xFF, $n, $i + 1)];
        }
        return $out;
    }

    /**
     * Nagłówek łączenia części z UDH (IEI 00 – 8-bitowy numer, IEI 08 – 16-bitowy).
     * @return array{ref:int,total:int,seq:int}|null
     */
    public static function parseUdh(string $udh): ?array
    {
        $udh = strtoupper(trim($udh));
        if ($udh === '' || !ctype_xdigit($udh) || strlen($udh) % 2 !== 0) {
            return null;
        }
        $bytes = array_map('hexdec', str_split($udh, 2));
        $len = $bytes[0];
        $i = 1;
        while ($i + 1 < count($bytes) && $i <= $len) {
            [$iei, $ielen] = [$bytes[$i], $bytes[$i + 1]];
            $data = array_slice($bytes, $i + 2, $ielen);
            if ($iei === 0x00 && $ielen === 3 && count($data) === 3) {
                return ['ref' => $data[0], 'total' => $data[1], 'seq' => $data[2]];
            }
            if ($iei === 0x08 && $ielen === 4 && count($data) === 4) {
                return ['ref' => $data[0] * 256 + $data[1], 'total' => $data[2], 'seq' => $data[3]];
            }
            $i += 2 + $ielen;
        }
        return null;
    }
}
