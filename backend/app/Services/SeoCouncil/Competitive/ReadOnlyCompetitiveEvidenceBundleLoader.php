<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

final class ReadOnlyCompetitiveEvidenceBundleLoader implements CompetitiveEvidenceBundleLoader
{
    public function load(MissionRequestData $request, string $releaseSha, string $environment): array
    {
        // Mission requests contain immutable references only. Runtime bundle bodies
        // must be explicitly bound by the controlled ingestion path in 11G-5.
        return [];
    }
}
