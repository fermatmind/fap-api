<?php

namespace App\Services\Psychometrics;

use App\Support\Stats\Percentile;
use App\Support\Stats\Stanine;
use App\Support\Stats\StandardError;
use App\Support\Stats\ZScore;

class ScoreNormalizer
{
    public function normalize(array $rawScores, array $bucket, float $confidence = 0.95): array
    {
        $stats = is_array($bucket['stats'] ?? null) ? $bucket['stats'] : [];
        $cdf = is_array($bucket['cdf'] ?? null) ? $bucket['cdf'] : [];

        $out = [
            'confidence' => $confidence,
            'dimensions' => [],
        ];

        foreach ($rawScores as $dim => $value) {
            $raw = is_numeric($value) ? (float) $value : null;
            if ($raw === null) {
                continue;
            }

            $dimStats = is_array($stats[$dim] ?? null) ? $stats[$dim] : [];
            $mean = isset($dimStats['mean']) ? (float) $dimStats['mean'] : 50.0;
            $sd = isset($dimStats['sd']) ? (float) $dimStats['sd'] : 10.0;
            $n = isset($dimStats['n']) ? (int) $dimStats['n'] : 0;

            $points = is_array($cdf[$dim] ?? null) ? $cdf[$dim] : [];
            $percentile = Percentile::fromCdfPoints($raw, $points, 50.0);
            $stanine = Stanine::fromPercentile($percentile);
            $z = ZScore::of($raw, $mean, $sd);

            $ci = StandardError::confidenceInterval($raw, $sd, $n, $confidence);
            $se = $ci['se'] ?? null;

            $out['dimensions'][$dim] = [
                'raw' => $raw,
                'mean' => $mean,
                'sd' => $sd,
                'n' => $n,
                'z' => round($z, 4),
                'percentile' => $percentile,
                'stanine' => $stanine,
                'se' => $se,
                'ci' => $ci,
            ];
        }

        return $out;
    }
}
