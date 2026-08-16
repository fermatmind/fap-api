<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class QualityPolicyResolver
{
    /** @param array<string,mixed> $registry @return array<string,mixed> */
    public function resolve(ReportContext $context, array $registry): array
    {
        $grade = $context->qualityGrade();
        $policy = data_get($registry, "shared.report_policy.quality_grades.{$grade}");
        if (! is_array($policy)) {
            $grade = 'UNKNOWN';
            $policy = data_get($registry, 'shared.report_policy.quality_grades.UNKNOWN', []);
        }

        return array_merge((array) $policy, [
            'grade' => $grade,
            'level' => $grade,
            'flags' => array_values(array_map('strval', is_array($context->quality['flags'] ?? null) ? $context->quality['flags'] : [])),
        ]);
    }
}
