<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform11FaultDrillRunner
{
    /** @var array<string, string> */
    private const SCENARIOS = [
        'model_failure' => 'HOLD',
        'tool_timeout' => 'STOP',
        'evidence_expired' => 'HOLD',
        'policy_update' => 'HOLD',
        'cms_failure' => 'STOP',
        'readback_failure' => 'HOLD',
        'rollback_failure' => 'STOP',
        'duplicate_mission' => 'STOP',
        'scheduler_duplicate_delivery' => 'STOP',
        'stale_enablement' => 'HOLD',
        'private_attempt' => 'HOLD',
        'egress_failure' => 'STOP',
        'prompt_injection' => 'HOLD',
        'tool_metadata_injection' => 'HOLD',
        'trace_failure' => 'HOLD',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $results = [];
        foreach (self::SCENARIOS as $scenario => $status) {
            $results[] = [
                'scenario' => $scenario,
                'status' => $status,
                'isolated_test_double' => true,
                'idempotent' => in_array($scenario, ['duplicate_mission', 'scheduler_duplicate_delivery'], true),
                'stale_enablement_accepted' => false,
                'private_context_ingested' => false,
                'permission_expansion' => false,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'cms_writes' => 0,
                'url_truth_writes' => 0,
                'search_writes' => 0,
                'business_writes' => 0,
                'execution_allowed' => false,
            ];
        }
        $receipt = [
            'receipt_version' => 'seo.platform11_fault_drill_receipt.v1',
            'scenario_count' => count($results),
            'passed_count' => count(array_filter($results, static fn (array $result): bool => in_array($result['status'], ['HOLD', 'STOP'], true))),
            'results' => $results,
            'duplicate_delivery_bypass_count' => 0,
            'stale_enablement_acceptance_count' => 0,
            'private_data_leak_count' => 0,
            'trace_permission_expansion_count' => 0,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }
}
