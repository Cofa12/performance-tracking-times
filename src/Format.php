<?php

namespace Cofa\PerformanceTracking;

final class Format
{
    public static function ms(?float $ms): string
    {
        return match (true) {
            $ms === null => '—',
            $ms == 0 => '0 ms',
            $ms >= 1000 => number_format($ms / 1000, 2).' s',
            $ms >= 100 => number_format($ms, 0).' ms',
            $ms >= 10 => number_format($ms, 1).' ms',
            default => number_format($ms, 2).' ms',
        };
    }

    /**
     * Evenly spaced axis ticks (1, 2 or 5 x 10^n) covering 0..$total.
     *
     * @return float[]
     */
    public static function ticks(float $total, int $target = 5): array
    {
        if ($total <= 0) {
            return [0.0];
        }

        $raw = $total / $target;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * 10;
        foreach ([1, 2, 5] as $factor) {
            if ($magnitude * $factor >= $raw) {
                $step = $magnitude * $factor;
                break;
            }
        }

        $ticks = [];
        for ($t = 0.0; $t <= $total + 1e-9; $t += $step) {
            $ticks[] = round($t, 6);
        }

        return $ticks;
    }
}
