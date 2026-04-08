<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class ScoreMath
{
    public static function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    public static function clamp100(float $value): int
    {
        return (int) round(max(0.0, min(100.0, $value)));
    }

    public static function normalizeNullable(mixed $value, float $default = 0.5): float
    {
        if (! is_numeric($value)) {
            return self::clamp01($default);
        }

        $numeric = (float) $value;
        if ($numeric > 1.0) {
            $numeric /= 100.0;
        }

        return self::clamp01($numeric);
    }

    /**
     * @param  array<string,float>  $components
     * @param  array<string,float>  $weights
     */
    public static function weightedGeometricMean(array $components, array $weights): float
    {
        $weightSum = 0.0;
        $logSum = 0.0;

        foreach ($weights as $key => $weight) {
            $normalizedWeight = max(0.0, (float) $weight);
            if ($normalizedWeight <= 0.0) {
                continue;
            }

            $component = self::clamp01((float) ($components[$key] ?? 0.0));
            $safeComponent = max($component, 0.001);
            $logSum += log($safeComponent) * $normalizedWeight;
            $weightSum += $normalizedWeight;
        }

        if ($weightSum <= 0.0) {
            return 0.0;
        }

        return self::clamp01((float) exp($logSum / $weightSum));
    }

    /**
     * @param  array<int,array{weight?:float,value?:float}>  $penalties
     */
    public static function penaltyFactor(array $penalties, float $floor = 0.2): float
    {
        $loss = 0.0;

        foreach ($penalties as $penalty) {
            $weight = max(0.0, (float) ($penalty['weight'] ?? 0.0));
            $value = self::clamp01((float) ($penalty['value'] ?? 0.0));
            $loss += $weight * $value;
        }

        return max($floor, 1.0 - $loss);
    }

    /**
     * @param  array<int,float>  $values
     */
    public static function average(array $values, float $default = 0.5): float
    {
        if ($values === []) {
            return self::clamp01($default);
        }

        $sum = array_sum($values);

        return self::clamp01($sum / count($values));
    }
}
