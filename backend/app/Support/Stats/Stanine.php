<?php

namespace App\Support\Stats;

class Stanine
{
    public static function fromPercentile(float $percentile): int
    {
        $p = max(0.0, min(100.0, $percentile));

        return match (true) {
            $p < 4 => 1,
            $p < 11 => 2,
            $p < 23 => 3,
            $p < 40 => 4,
            $p < 60 => 5,
            $p < 77 => 6,
            $p < 89 => 7,
            $p < 96 => 8,
            default => 9,
        };
    }
}
