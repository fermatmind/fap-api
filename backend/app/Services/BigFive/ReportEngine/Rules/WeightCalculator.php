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

        if ($formula === 'abs(N-50)+abs(E-50)+abs(N-E)*0.5') {
            $n = $context->domainPercentile('N');
            $e = $context->domainPercentile('E');

            return abs($n - 50) + abs($e - 50) + abs($n - $e) * 0.5;
        }

        return $fallback;
    }
}
