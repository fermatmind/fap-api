<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

interface TechnicalDiagnosisDependencyBindingSource
{
    /** @return array<string, mixed> */
    public function technicalDiagnosisBinding(string $releaseSha): array;
}
