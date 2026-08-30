<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class TechnicalDiagnosisModeRegistry
{
    public function __construct(
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function references(): array
    {
        $manifest = $this->contracts->manifest();
        $output = collect((array) $manifest['contracts'])->firstWhere('id', 'seo.technical_diagnosis_output.v1');

        return [
            'technical_diagnosis_mode_version' => $manifest['mode']['version'],
            'technical_diagnosis_mode_hash' => $manifest['mode']['hash'],
            'technical_diagnosis_prompt_version' => $manifest['prompt']['version'],
            'technical_diagnosis_prompt_hash' => $manifest['prompt']['hash'],
            'technical_diagnosis_policy_version' => $manifest['policy']['version'],
            'technical_diagnosis_policy_hash' => $manifest['policy']['hash'],
            'output_schema_version' => $output['version'] ?? null,
            'output_schema_hash' => $output['hash'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function capabilitySnapshot(): array
    {
        $snapshot = [
            'snapshot_id' => 'seo.technical_diagnosis_runtime_capability_snapshot.v1',
            'mode_id' => 'technical_search_diagnosis',
            'mode_state' => 'OFFLINE_EVAL_READY',
            'production_model_enabled' => false,
            'production_tool_enabled' => false,
            'production_execution_enabled' => false,
            'production_write_enabled' => false,
            'allow_delegation' => false,
            'external_egress_enabled' => false,
            'active_production_manifests' => 0,
            'trusted_production_keys' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
            'references' => $this->references(),
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }
}
