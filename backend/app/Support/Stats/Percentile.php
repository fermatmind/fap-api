<?php

namespace App\Support\Stats;

class Percentile
{
    public static function fromCdfPoints(float $score, array $points, float $default = 50.0): float
    {
        $cdf = new BucketCdf($points);
        return $cdf->percentile($score, $default);
    }
}
