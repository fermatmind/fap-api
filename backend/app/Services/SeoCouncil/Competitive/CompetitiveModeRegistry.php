<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class CompetitiveModeRegistry
{
    public function __construct(
        private readonly CompetitiveEvidenceContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function capabilitySnapshot(): array
    {
        $manifest = $this->contracts->manifest();
        $snapshot = [
            'snapshot_id' => 'seo.competitive_runtime_capability_snapshot.v1',
            'mode_id' => 'competitive_evidence',
            'mode_state' => 'OFFLINE_EVAL_READY',
            'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'production_model_enabled' => false,
            'production_tool_enabled' => false,
            'production_execution_enabled' => false,
            'production_write_enabled' => false,
            'external_egress_enabled' => false,
            'allow_delegation' => false,
            'production_permissions' => 0,
            'execution_allowed' => false,
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }
}
