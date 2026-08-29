<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Governance;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class RuntimeCapabilitySnapshotBuilder
{
    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $persistence = ! app()->environment('production')
            && (bool) config('seo_council.mission_persistence_enabled', false);
        $snapshot = [
            'snapshot_id' => 'seo.runtime_capability_snapshot.v1',
            'orchestrator_state' => 'DEPLOYED_DISABLED',
            'runtime_mode' => 'DETERMINISTIC_ROUTE_HOLD_ONLY',
            'career_runtime' => (bool) config('seo_council.career_runtime_enabled', false)
                ? 'available'
                : 'unavailable_manifest_validator_risk_open',
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'agent_write_permissions' => 0,
            'active_manifests' => 0,
            'trusted_signing_keys' => 0,
            'l4' => 'dormant_not_authorized',
            'mission_execution_enabled' => false,
            'mission_persistence_enabled' => $persistence,
            'execution_allowed' => false,
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }
}
