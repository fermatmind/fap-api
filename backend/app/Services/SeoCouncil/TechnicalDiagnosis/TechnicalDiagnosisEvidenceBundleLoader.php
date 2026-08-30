<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

interface TechnicalDiagnosisEvidenceBundleLoader
{
    /** @return list<array<string, mixed>> */
    public function load(MissionRequestData $request): array;
}
