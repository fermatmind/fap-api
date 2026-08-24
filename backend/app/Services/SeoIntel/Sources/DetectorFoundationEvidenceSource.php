<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use Carbon\CarbonImmutable;

interface DetectorFoundationEvidenceSource
{
    /**
     * @return array{jobs:list<array<string,mixed>>,metadata:array<string,mixed>,issues:list<string>}
     */
    public function snapshot(CarbonImmutable $observedAt): array;
}
