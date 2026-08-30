<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class MeasurementModeRegistry
{
    public function __construct(
        private readonly MeasurementContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function references(): array
    {
        $manifest = $this->contracts->manifest();

        return array_intersect_key($manifest, array_flip([
            'search_mode', 'search_prompt', 'search_policy', 'cro_mode', 'cro_prompt', 'cro_policy', 'manifest_hash',
        ]));
    }

    /** @return array<string, mixed> */
    public function capabilitySnapshot(): array
    {
        $snapshot = [
            'snapshot_id' => 'seo.measurement_runtime_capability_snapshot.v1',
            'mode_state' => 'OFFLINE_EVAL_READY',
            'available_modes' => ['search_measurement', 'commercial_funnel_cro'],
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
