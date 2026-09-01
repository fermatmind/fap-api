<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

final class DenyOnlyCompetitiveRuntimeGate implements CompetitiveRuntimeGate
{
    public function allows(array $capabilitySnapshot): bool
    {
        return false;
    }
}
