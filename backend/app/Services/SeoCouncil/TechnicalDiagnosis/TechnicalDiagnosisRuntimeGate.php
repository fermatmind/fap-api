<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

interface TechnicalDiagnosisRuntimeGate
{
    /** @param array<string, mixed> $capabilitySnapshot */
    public function allows(array $capabilitySnapshot): bool;
}
