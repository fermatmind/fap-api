<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

interface CompetitiveEvidenceBundleLoader
{
    /** @return list<array<string, mixed>> */
    public function load(MissionRequestData $request, string $releaseSha, string $environment): array;
}
