<?php

namespace App\Support\Stats;

class ZScore
{
    public static function of(float $value, float $mean, float $sd): float
    {
        if ($sd <= 0.0) {
            return 0.0;
        }

        return ($value - $mean) / $sd;
    }
}
