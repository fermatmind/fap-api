<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;

final class TechnicalDiagnosisActivityLedger
{
    /** @var list<string> */
    private const ACTIVITIES = [
        'model_calls', 'tool_calls', 'external_calls', 'business_writes', 'cms_writes',
        'url_truth_writes', 'canonical_writes', 'robots_writes', 'feed_writes', 'search_writes',
        'runner_calls', 'active_manifest_count', 'trusted_key_count', 'l4_allow_count',
        'production_permissions',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly PolicyGatewayRegistry $policy) {}

    public function record(string $activity): void
    {
        if (! in_array($activity, self::ACTIVITIES, true)) {
            throw new \InvalidArgumentException('UNKNOWN_TECHNICAL_DIAGNOSIS_ACTIVITY');
        }
        $this->counts[$activity] = ($this->counts[$activity] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $counts = array_fill_keys(self::ACTIVITIES, 0);
        foreach ($this->counts as $activity => $count) {
            $counts[$activity] = $count;
        }
        $trust = $this->policy->trustRegistry();
        $guards = $this->policy->guards();

        return [
            ...$counts,
            'active_manifest_count' => $counts['active_manifest_count'] + count((array) ($trust['active_manifest_ids'] ?? [])),
            'trusted_key_count' => $counts['trusted_key_count'] + count((array) ($trust['trusted_public_keys'] ?? [])),
            'l4_allow_count' => $counts['l4_allow_count'] + (($guards['l4_state'] ?? null) === 'dormant_not_authorized' ? 0 : 1),
            'production_permissions' => $counts['production_permissions'] + (($guards['agent_default_write_permission'] ?? null) === false ? 0 : 1),
        ];
    }
}
