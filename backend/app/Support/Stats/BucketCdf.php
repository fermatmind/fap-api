<?php

namespace App\Support\Stats;

class BucketCdf
{
    private array $points;

    public function __construct(array $points)
    {
        $this->points = $this->normalizePoints($points);
    }

    public function percentile(float $score, float $default = 50.0): float
    {
        $points = $this->points;
        if (count($points) === 0) {
            return $default;
        }

        $first = $points[0];
        $last = $points[count($points) - 1];

        if ($score <= $first['score']) {
            return $this->clampPercentile($first['cdf']);
        }
        if ($score >= $last['score']) {
            return $this->clampPercentile($last['cdf']);
        }

        for ($i = 0; $i < count($points) - 1; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];

            if ($score < $a['score'] || $score > $b['score']) {
                continue;
            }

            if ($b['score'] == $a['score']) {
                return $this->clampPercentile($b['cdf']);
            }

            $t = ($score - $a['score']) / ($b['score'] - $a['score']);
            $cdf = $a['cdf'] + $t * ($b['cdf'] - $a['cdf']);

            return $this->clampPercentile($cdf);
        }

        return $default;
    }

    private function normalizePoints(array $points): array
    {
        $out = [];

        foreach ($points as $p) {
            if (!is_array($p)) {
                continue;
            }

            $score = $p['score'] ?? $p['value'] ?? null;
            $cdf = $p['cdf'] ?? $p['percentile'] ?? null;

            if (!is_numeric($score) || !is_numeric($cdf)) {
                continue;
            }

            $out[] = [
                'score' => (float) $score,
                'cdf' => (float) $cdf,
            ];
        }

        usort($out, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_values($out);
    }

    private function clampPercentile(float $cdf): float
    {
        $pct = $cdf;
        if ($pct > 1.0) {
            $pct = $pct / 100.0;
        }

        $pct = max(0.0, min(1.0, $pct));

        return round($pct * 100.0, 2);
    }
}
