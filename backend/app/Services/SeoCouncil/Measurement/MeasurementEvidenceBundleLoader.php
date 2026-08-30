<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

interface MeasurementEvidenceBundleLoader
{
    /** @return list<array<string, mixed>> */
    public function loadForScope(
        string $missionId,
        string $modeId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): array;
}
