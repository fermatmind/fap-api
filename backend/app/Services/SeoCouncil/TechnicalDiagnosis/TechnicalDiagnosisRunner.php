<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

interface TechnicalDiagnosisRunner
{
    /** @param array<string, mixed> $handoff @return array<string, mixed> */
    public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array;
}
