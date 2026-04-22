<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Rules;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class WeightCalculator
{
    public function calculate(string $formula, ReportContext $context, float $fallback): float
    {
        $formula = trim($formula);
        if ($formula === '') {
            return $fallback;
        }

        $total = 0.0;
        foreach (explode('+', str_replace(' ', '', $formula)) as $term) {
            if ($term === '') {
                continue;
            }

            if (! preg_match('/^abs\\(([A-Z])-(\\d+|[A-Z])\\)(?:\\*(\\d+(?:\\.\\d+)?))?$/', $term, $matches)) {
                return $fallback;
            }

            $left = $context->domainPercentile($matches[1]);
            $right = preg_match('/^[A-Z]$/', $matches[2]) === 1
                ? $context->domainPercentile($matches[2])
                : (float) $matches[2];
            $multiplier = isset($matches[3]) ? (float) $matches[3] : 1.0;
            $total += abs($left - $right) * $multiplier;
        }

        return $total;
    }
}
