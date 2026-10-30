<?php
declare(strict_types=1);

/** Proste wykresy SVG generowane po stronie serwera (bez bibliotek); kolory z klas CSS .chart-* (motyw jasny i ciemny). */
final class Chart
{
    private const W = 640;
    private const H = 220;
    private const ML = 40;
    private const MR = 10;
    private const MT = 10;
    private const MB = 26;

    /** Zaokrąglenie górnej granicy osi do „ładnej” wartości (1, 2, 5 × 10^n). */
    public static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $pow = 10 ** floor(log10($max));
        foreach ([1, 2, 5, 10] as $m) {
            if ($max <= $m * $pow) {
                return $m * $pow;
            }
        }
        return 10 * $pow;
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function frame(string $title, string $desc, float $top, string $yFormat): string
    {
        $w = self::W - self::ML - self::MR;
        $h = self::H - self::MT - self::MB;
        $svg = '<svg class="chart" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-label="' . e($title) . '"><title>' . e($title) . '</title><desc>' . e($desc) . '</desc>';
        for ($i = 0; $i <= 4; $i++) {
            $y = self::MT + $h - $h * $i / 4;
            $svg .= '<line class="chart-grid" x1="' . self::ML . '" x2="' . (self::ML + $w) . '" y1="' . self::f($y) . '" y2="' . self::f($y) . '"/>'
                . '<text class="chart-text" x="' . (self::ML - 6) . '" y="' . self::f($y + 4) . '" text-anchor="end">' . e(sprintf($yFormat, $top * $i / 4)) . '</text>';
        }
        return $svg;
    }

    /**
     * Wykres słupkowy grupowany.
     * @param list<string> $labels etykiety osi X (każda grupa), @param array<string, list<int|float>> $series nazwa => wartości, kolory: kolejność serii
     */
    public static function bars(string $title, string $desc, array $labels, array $series): string
    {
        $n = max(1, count($labels));
        $max = 0;
        foreach ($series as $vals) {
            $max = max($max, ...($vals ?: [0]));
        }
        $top = self::niceMax((float) $max);
        $w = self::W - self::ML - self::MR;
        $h = self::H - self::MT - self::MB;
        $svg = self::frame($title, $desc, $top, '%g');
        $gw = $w / $n;
        $k = max(1, count($series));
        $bw = max(1.0, min(18.0, $gw * 0.8 / $k));
        $step = max(1, (int) ceil($n / 8));
        foreach ($labels as $i => $label) {
            $x0 = self::ML + $gw * $i + ($gw - $bw * $k) / 2;
            $s = 0;
            foreach ($series as $name => $vals) {
                $v = (float) ($vals[$i] ?? 0);
                $bh = $top > 0 ? $h * $v / $top : 0;
                if ($v > 0) {
                    $svg .= '<rect class="chart-s' . ($s + 1) . '" x="' . self::f($x0 + $bw * $s) . '" y="' . self::f(self::MT + $h - $bh) . '" width="' . self::f($bw - 1) . '" height="' . self::f($bh)
                        . '"><title>' . e($label . ' · ' . $name . ': ' . (int) $v) . '</title></rect>';
                }
                $s++;
            }
            if ($i % $step === 0) {
                $svg .= '<text class="chart-text" x="' . self::f(self::ML + $gw * ($i + 0.5)) . '" y="' . (self::H - 8) . '" text-anchor="middle">' . e($label) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /**
     * Wykres liniowy w czasie.
     * @param list<array{t:int,y:float}> $points rosnąco po czasie, @param ?float $line pozioma linia pomocnicza (np. próg)
     */
    public static function line(string $title, string $desc, array $points, int $from, int $to, ?float $line = null, string $unit = ''): string
    {
        $ys = array_column($points, 'y');
        $max = max($ys ? max($ys) : 0, $line ?? 0);
        $top = self::niceMax((float) $max);
        $w = self::W - self::ML - self::MR;
        $h = self::H - self::MT - self::MB;
        $svg = self::frame($title, $desc, $top, '%g');
        $span = max(1, $to - $from);
        $x = static fn (int $t) => self::ML + $w * (max($from, min($to, $t)) - $from) / $span;
        $y = static fn (float $v) => self::MT + $h - ($top > 0 ? $h * $v / $top : 0);
        if ($line !== null && $line > 0) {
            $svg .= '<line class="chart-limit" x1="' . self::ML . '" x2="' . (self::ML + $w) . '" y1="' . self::f($y($line)) . '" y2="' . self::f($y($line)) . '"/>';
        }
        if ($points !== []) {
            $svg .= '<polyline class="chart-line" fill="none" points="' . implode(' ', array_map(static fn ($p) => self::f($x($p['t'])) . ',' . self::f($y($p['y'])), $points)) . '"/>';
            foreach ($points as $p) {
                $svg .= '<circle class="chart-dot" cx="' . self::f($x($p['t'])) . '" cy="' . self::f($y($p['y'])) . '" r="3"><title>' . e(date('d.m H:i', $p['t']) . ' · ' . self::f($p['y']) . $unit) . '</title></circle>';
            }
        }
        for ($i = 0; $i <= 4; $i++) {
            $t = $from + intdiv($span * $i, 4);
            $svg .= '<text class="chart-text" x="' . self::f($x($t)) . '" y="' . (self::H - 8) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === 4 ? 'end' : 'middle')) . '">' . e(date('d.m', $t)) . '</text>';
        }
        return $svg . '</svg>';
    }
}
