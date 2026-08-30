<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

final class ReadOnlyTechnicalDiagnosisEvidenceBundleLoader implements TechnicalDiagnosisEvidenceBundleLoader
{
    public function load(MissionRequestData $request): array
    {
        // Council requests carry immutable references, not bundle bodies. A runtime
        // source must be explicitly bound before the post-admission gate can open.
        return [];
    }
}
