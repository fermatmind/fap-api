<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

interface MeasurementEvidenceDiagnosticLoader
{
    public function diagnoseForScope(
        string $missionId,
        string $modeId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): MeasurementEvidenceLoadResult;

    public function diagnoseForRuntime(
        string $missionId,
        string $modeId,
        string $environment,
    ): MeasurementEvidenceLoadResult;
}
