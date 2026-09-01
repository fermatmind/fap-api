<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

interface CompetitiveRuntimeGate
{
    /** @param array<string, mixed> $capabilitySnapshot */
    public function allows(array $capabilitySnapshot): bool;
}
