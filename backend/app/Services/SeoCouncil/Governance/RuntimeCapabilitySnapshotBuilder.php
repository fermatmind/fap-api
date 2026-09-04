<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Governance;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoPromptRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationPolicyContract;
use App\Services\SeoCouncil\Platform12\Platform12ReadOnlyRuntimeGate;
use App\Services\SeoCouncil\Platform12\Tool\Platform12ToolManifest;

final class RuntimeCapabilitySnapshotBuilder
{
    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoPromptRegistry $prompts,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly PolicyGatewayRegistry $policy,
        private readonly CouncilContractRegistry $contracts,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly Platform12ReadOnlyRuntimeGate $readOnlyGate,
        private readonly Platform12ToolManifest $platform12Tools,
        private readonly Platform12NotificationPolicyContract $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(?array $expectedVersionVector = null): array
    {
        $persistenceState = (string) config('seo_council.mission_persistence_runtime_state', 'DISABLED');
        $persistence = (bool) config('seo_council.mission_persistence_enabled', false)
            && $persistenceState === 'ACTIVE';
        $versionVector = $this->versionVector();
        $configuredExpected = config('seo_council.read_only_runtime_expected_version_vector', []);
        $expected = $expectedVersionVector
            ?? (is_array($configuredExpected) && $configuredExpected !== [] ? $configuredExpected : $versionVector);
        $lifecycle = $this->readOnlyGate->evaluate(
            (string) config('seo_council.read_only_runtime_state', 'OFFLINE_EVAL'),
            (bool) config('seo_council.read_only_runtime_test_enabled', false),
            (string) app()->environment(),
            $expected,
            $versionVector,
        );
        $readOnlyEnabled = $lifecycle['read_only_runtime_enabled'];
        $snapshot = [
            'snapshot_id' => 'seo.runtime_capability_snapshot.v1',
            'orchestrator_state' => $readOnlyEnabled ? 'TEST_READ_ONLY' : 'DEPLOYED_DISABLED',
            'runtime_mode' => $readOnlyEnabled ? 'READ_ONLY_STRUCTURED_OUTPUT' : 'DETERMINISTIC_ROUTE_HOLD_ONLY',
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
            'mission_persistence_runtime_state' => $persistenceState,
            'mission_persistence_enabled' => $persistence,
            'read_only_runtime_state' => $lifecycle['state'],
            'read_only_runtime_reason' => $lifecycle['reason'],
            'read_only_runtime_test_switch' => (bool) config('seo_council.read_only_runtime_test_enabled', false),
            'read_only_runtime_enabled' => $readOnlyEnabled,
            'read_only_output_boundary' => 'structured_artifact_only',
            'version_vector' => $versionVector,
            'version_vector_hash' => $this->hasher->hash($versionVector),
            'changed_dimensions' => $lifecycle['changed_dimensions'],
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }

    /** @return array<string, string> */
    private function versionVector(): array
    {
        return [
            'role' => (string) $this->roles->registry()['registry_hash'],
            'prompt' => $this->hasher->hash($this->prompts->definitions()),
            'model' => $this->hasher->hash(['provider' => (string) config('seo_council.model_provider', 'disabled')]),
            'tool' => $this->platform12Tools->reference()['hash'],
            'policy' => $this->hasher->hash([
                'gateway' => (string) $this->policy->registry()['registry_hash'],
                'notification' => $this->notifications->reference()['hash'],
            ]),
            'schema' => (string) $this->contracts->manifest()['manifest_hash'],
            'evidence' => (string) $this->evidence->manifest()['manifest_hash'],
            'binding' => (string) $this->binding->reference()['hash'],
        ];
    }
}
