<?php

namespace App\Support\Stats;

class StandardError
{
    public static function forMean(float $sd, int $n): ?float
    {
        if ($sd <= 0.0 || $n <= 0) {
            return null;
        }

        return $sd / sqrt($n);
    }

    public static function confidenceInterval(float $value, float $sd, int $n, float $confidence = 0.95): array
    {
        $se = self::forMean($sd, $n);
        $z = self::zForConfidence($confidence);

        if ($se === null || $z === null) {
            return [
                'lower' => null,
                'upper' => null,
                'se' => $se,
                'confidence' => $confidence,
                'z' => $z,
            ];
        }

        return [
            'lower' => $value - $z * $se,
            'upper' => $value + $z * $se,
            'se' => $se,
            'confidence' => $confidence,
            'z' => $z,
        ];
    }

    public static function zForConfidence(float $confidence): ?float
    {
        $map = [
            0.80 => 1.282,
            0.90 => 1.645,
            0.95 => 1.960,
            0.98 => 2.326,
            0.99 => 2.576,
        ];

        foreach ($map as $c => $z) {
            if (abs($confidence - $c) < 0.0001) {
                return $z;
            }
        }

        if ($confidence > 0.0 && $confidence < 1.0) {
            return 1.960;
        }

        return null;
    }
}
