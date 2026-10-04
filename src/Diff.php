<?php
declare(strict_types=1);

/** Różnice linia po linii (LCS) do okna potwierdzenia zapisu i porównania kopii (rozdz. 2.10). */
final class Diff
{
    /** @return list<array{0:string,1:string}> [op ' ' | '+' | '-', linia] */
    public static function lines(string $old, string $new): array
    {
        $a = explode("\n", rtrim(str_replace("\r\n", "\n", $old), "\n"));
        $b = explode("\n", rtrim(str_replace("\r\n", "\n", $new), "\n"));
        $n = count($a);
        $m = count($b);
        // Wspólny początek i koniec – LCS liczony tylko dla środka
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $n - 1;
        $endB = $m - 1;
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
            $endA--;
            $endB--;
        }
        $midA = array_slice($a, $start, $endA - $start + 1);
        $midB = array_slice($b, $start, $endB - $start + 1);
        $la = count($midA);
        $lb = count($midB);
        $L = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $L[$i][$j] = $midA[$i] === $midB[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $out = [];
        foreach (array_slice($a, 0, $start) as $line) {
            $out[] = [' ', $line];
        }
        [$i, $j] = [0, 0];
        while ($i < $la || $j < $lb) {
            if ($i < $la && $j < $lb && $midA[$i] === $midB[$j]) {
                $out[] = [' ', $midA[$i]];
                $i++;
                $j++;
            } elseif ($i < $la && ($j >= $lb || $L[$i + 1][$j] >= $L[$i][$j + 1])) {
                $out[] = ['-', $midA[$i++]]; // zmieniona linia: najpierw stara, potem nowa
            } else {
                $out[] = ['+', $midB[$j++]];
            }
        }
        foreach (array_slice($a, $endA + 1) as $line) {
            $out[] = [' ', $line];
        }
        return $out;
    }

    public static function changed(array $diff): int
    {
        return count(array_filter($diff, static fn ($d) => $d[0] !== ' '));
    }

    /** Zmienione linie z kontekstem (pozostałe pominięte jako „…”). */
    public static function context(array $diff, int $ctx = 2): array
    {
        $keep = [];
        foreach ($diff as $i => $d) {
            if ($d[0] !== ' ') {
                for ($k = max(0, $i - $ctx); $k <= min(count($diff) - 1, $i + $ctx); $k++) {
                    $keep[$k] = true;
                }
            }
        }
        $out = [];
        $last = -1;
        foreach ($diff as $i => $d) {
            if (!isset($keep[$i])) {
                continue;
            }
            if ($last >= 0 && $i > $last + 1) {
                $out[] = ['…', ''];
            }
            $out[] = $d;
            $last = $i;
        }
        return $out;
    }

    /** HTML do <pre class="codebox">: dodane na zielono, usunięte na czerwono. */
    public static function html(array $diff): string
    {
        $html = '';
        foreach ($diff as [$op, $line]) {
            $class = match ($op) { '+' => ' class="add"', '-' => ' class="del"', '…' => ' class="dim"', default => '' };
            $html .= '<span' . $class . '>' . ($op === '…' ? '  …' : e($op . ' ' . $line)) . '</span>';
        }
        return $html;
    }
}
