<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\PageFamily;

final class PageFamilyPolicyGuard
{
    public function __construct(
        private readonly ?PageFamilyClassifier $classifier = null,
    ) {}

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    public function evaluate(array $authority, string $requestedRiskLevel): array
    {
        $classification = ($this->classifier ?? new PageFamilyClassifier)->classify($authority);
        $cap = (string) ($classification['agent_risk_cap'] ?? 'L0');
        $requested = $this->riskOrdinal($requestedRiskLevel);
        $allowedCap = $this->riskOrdinal($cap);
        $reasons = (array) ($classification['blocking_reasons'] ?? []);

        if (($classification['classification_status'] ?? '') !== 'classified') {
            $reasons[] = 'page_family_not_formally_classified';
        }
        if ($requested > $allowedCap) {
            $reasons[] = 'agent_risk_exceeds_family_cap';
        }

        return [
            ...$classification,
            'requested_risk_level' => strtoupper(trim($requestedRiskLevel)),
            'allowed' => $reasons === [],
            'blocking_reasons' => array_values(array_unique($reasons)),
            'existing_claim_review_cms_and_search_boundaries_preserved' => true,
        ];
    }

    private function riskOrdinal(string $risk): int
    {
        return match (strtoupper(trim($risk))) {
            'L0' => 0,
            'L1' => 1,
            'L2' => 2,
            'L3' => 3,
            default => PHP_INT_MAX,
        };
    }
}
