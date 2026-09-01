<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

interface CompetitiveRunner
{
    /** @param array<string, mixed> $handoff @return array<string, mixed> */
    public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array;
}
