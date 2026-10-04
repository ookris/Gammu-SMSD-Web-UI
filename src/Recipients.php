<?php
declare(strict_types=1);

/** Odbiorcy wysyłki (rozdz. 2.3): grupy + kontakty + numery ręcznie, personalizacja, dławienie, okno wysyłki. */
final class Recipients
{
    /** Numery wpisane ręcznie: oddzielone przecinkiem, średnikiem lub nową linią. */
    public static function splitManual(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/', $text) ?: []), 'strlen'));
    }

    /**
     * Suma bez duplikatów.
     * @return array{list:list<array{phone:string,name:?string}>,invalid:list<array{0:string,1:string}>,duplicates:int,blocked:list<string>,outside:int}
     */
    public static function resolve(array $groupIds, array $contactIds, string $manual): array
    {
        $candidates = [];
        if ($groupIds !== []) {
            foreach (Db::all('SELECT DISTINCT c.phone, c.name FROM contacts c JOIN contact_group_members m ON m.contact_id = c.id
                WHERE m.group_id IN (' . Db::in($groupIds) . ') ORDER BY c.name', $groupIds) as $r) {
                $candidates[] = $r;
            }
        }
        if ($contactIds !== []) {
            foreach (Db::all('SELECT phone, name FROM contacts WHERE id IN (' . Db::in($contactIds) . ') ORDER BY name', $contactIds) as $r) {
                $candidates[] = $r;
            }
        }
        $invalid = [];
        foreach (self::splitManual($manual) as $raw) {
            $phone = Phone::normalize($raw);
            if ($phone === null) {
                $invalid[] = [$raw, (string) Phone::error($raw)];
                continue;
            }
            $candidates[] = ['phone' => $phone, 'name' => Contacts::name($phone)];
        }
        $list = [];
        $dup = 0;
        foreach ($candidates as $c) {
            if (isset($list[$c['phone']])) {
                $dup++;
                continue;
            }
            $list[$c['phone']] = ['phone' => $c['phone'], 'name' => $c['name']];
        }
        $outside = count(array_filter($list, static fn ($r) => $r['name'] === null));
        return ['list' => array_values($list), 'invalid' => $invalid, 'duplicates' => $dup,
            'blocked' => Blocklist::blockedAmong(array_keys($list)), 'outside' => $outside];
    }

    public static function usesVars(string $text): bool
    {
        return (bool) preg_match('/\{(imie|nazwa)\}/u', $text);
    }

    /** {nazwa} – pełna nazwa kontaktu, {imie} – pierwsze słowo; odbiorca spoza książki – pusty tekst. */
    public static function personalize(string $text, ?string $name): string
    {
        $name = trim((string) $name);
        $first = $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');
        return str_replace(['{nazwa}', '{imie}'], [$name, $first], $text);
    }

    /** Najdłuższy wariant treści po personalizacji (licznik liczy najgorszy przypadek). */
    public static function longest(string $text, array $list, bool $translit): array
    {
        $best = ['text' => $translit ? SmsText::translit($text) : $text, 'name' => null];
        if (!self::usesVars($text)) {
            return $best + ['analysis' => SmsText::analyze($best['text'])];
        }
        $bestA = null;
        foreach ($list ?: [['name' => null]] as $r) {
            $t = self::personalize($text, $r['name']);
            if ($translit) {
                $t = SmsText::translit($t);
            }
            $a = SmsText::analyze($t);
            if ($bestA === null || $a['units'] + ($a['gsm'] ? 0 : 1000) > $bestA['units'] + ($bestA['gsm'] ? 0 : 1000)) {
                [$best, $bestA] = [['text' => $t, 'name' => $r['name']], $a];
            }
        }
        return $best + ['analysis' => $bestA];
    }

    /** Okno wysyłki do wielu odbiorców z ustawień: [SendAfter, SendBefore] albo null (wyłączone). */
    public static function window(): ?array
    {
        if (!Settings::bool('window_enabled')) {
            return null;
        }
        $from = (string) Settings::get('window_from');
        $to = (string) Settings::get('window_to');
        if (!self::validTime($from) || !self::validTime($to) || $from >= $to) {
            return null;
        }
        return [$from . ':00', $to . ':59'];
    }

    /** Godzina HH:MM w zakresie 00:00–23:59. */
    public static function validTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /** Pierwszy moment wysyłki w oknie, licząc od $t (bez zmian, gdy $t jest w oknie). */
    public static function windowStart(array $window, int $t): int
    {
        $time = date('H:i:s', $t);
        if (Outbox::inWindow($window, $time)) {
            return $t;
        }
        $day = $time > $window[1] ? strtotime('+1 day', $t) : $t;
        return (int) strtotime(date('Y-m-d', $day) . ' ' . $window[0]);
    }

    /** Dławienie: czasy wysyłki kolejnych wiadomości rozłożone równo wg N SMS/min (0 = bez limitu). */
    public static function schedule(int $count, int $perMinute, int $start): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = date('Y-m-d H:i:s', $perMinute > 0 ? $start + (int) floor($i * 60 / $perMinute) : $start);
        }
        return $out;
    }

    /** Szacowany czas wysyłki w sekundach. */
    public static function duration(int $count, int $perMinute): int
    {
        return $perMinute > 0 && $count > 1 ? (int) ceil(($count - 1) * 60 / $perMinute) : 0;
    }
}
