<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Rules;

final class RuleOperators
{
    public function compare(float $actual, string $operator, float|array $expected): bool
    {
        return match ($operator) {
            '>=' => $actual >= (float) $expected,
            '<=' => $actual <= (float) $expected,
            '>' => $actual > (float) $expected,
            '<' => $actual < (float) $expected,
            '==' => $actual === (float) $expected,
            'between' => $this->between($actual, $expected),
            default => false,
        };
    }

    /**
     * @param  float|array<mixed>  $range
     */
    public function between(float $actual, float|array $range): bool
    {
        if (! is_array($range) || count($range) < 2) {
            return false;
        }

        $min = (float) array_values($range)[0];
        $max = (float) array_values($range)[1];

        return $actual >= $min && $actual <= $max;
    }

    public function absDiffGe(float $left, float $right, float $threshold): bool
    {
        return abs($left - $right) >= $threshold;
    }
}
