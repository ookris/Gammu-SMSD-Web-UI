<?php
declare(strict_types=1);

/** Drobne funkcje sieciowe wspólne dla webhooka i listy adresów IP tokenów API. */
final class Net
{
    /** Adres binarny (inet_pton): ::ffff:a.b.c.d (IPv4 w gnieździe dwustosowym) sprowadzony do 4 bajtów IPv4. */
    public static function unmap(string $bin): string
    {
        return strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") ? substr($bin, 12) : $bin;
    }
}
