<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SeoAgentGovernance;

use App\Services\SeoAgentGovernance\SeoPolicyRegistry;
use App\Services\SeoAgentGovernance\SeoPromptRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Tests\TestCase;

final class SeoRoleCapabilityRegistryTest extends TestCase
{
    public function test_frozen_registry_is_the_single_hash_bound_authority(): void
    {
        $authority = app(SeoRoleCapabilityRegistry::class);
        $hasher = app(SeoRegistryHasher::class);
        $registry = $authority->registry();
        $projection = $this->jsonFile(base_path('docs/seo/generated/seo-agent-role-capability-registry.v1.json'));

        $this->assertSame($registry, $projection);
        $this->assertSame('frozen', $registry['registry_status']);
        $this->assertSame('fap-api', $registry['owner_repository']);
        $this->assertSame($hasher->hashWithout($registry, 'registry_hash'), $registry['registry_hash']);
        $this->assertSame([
            'runtime_framework' => 'laravel_native',
            'orchestration_pattern' => 'flow_first',
            'crewai_runtime_dependency' => false,
            'shared_agent_memory' => false,
            'delegation_default' => false,
            'external_trace_export' => false,
        ], $registry['architecture_decisions']);

        foreach ([
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'post12_agent_write_enabled' => false,
            'l4_state' => 'dormant_not_authorized',
            'agent_default_write_permission' => false,
            'deterministic_system_final_veto' => true,
            'model_invocation_enabled' => false,
            'runtime_model_invocation_enabled' => false,
            'fap_web_agent_authority' => false,
        ] as $guard => $expected) {
            $this->assertSame($expected, $registry['global_guards'][$guard]);
        }
    }

    public function test_roles_capabilities_and_bindings_are_unique_dormant_and_zero_authority(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $roles = $registry['roles'];
        $capabilities = $registry['capabilities'];
        $roleIds = array_column($roles, 'role_id');
        $capabilityIds = array_column($capabilities, 'capability_id');

        $this->assertCount(9, $roles);
        $this->assertCount(count(array_unique($roleIds)), $roleIds);
        $this->assertCount(1, array_filter($roles, static fn (array $role): bool => $role['role_id'] === 'seo.orchestrator'));
        $this->assertCount(1, array_filter($roles, static fn (array $role): bool => $role['role_id'] === 'career.content_agent'));
        $this->assertCount(count(array_unique($capabilityIds)), $capabilityIds);

        foreach ($roles as $role) {
            $this->assertSame($hasher->hashWithout($role, 'role_hash'), $role['role_hash']);
            $this->assertSame('dormant_not_authorized', $role['runtime_state']);
            $this->assertSame([], $role['tool_allowlist']);
            $this->assertSame([], $role['egress_allowlist']);
            $this->assertSame([], $role['write_permissions']);
            $this->assertFalse($role['allow_delegation']);
            $this->assertSame(0, $role['max_model_calls']);
            $this->assertSame(0, $role['max_tool_calls']);
            $this->assertSame(0, $role['max_execution_seconds']);
            $this->assertSame(0, $role['max_retry_count']);
            $this->assertSame(0, $role['max_cost']['amount']);
            $this->assertContains($role['authority_ceiling'], ['recommendation_only', 'candidate_only', 'review_verdict']);
        }

        foreach ($capabilities as $capability) {
            $this->assertSame($hasher->hashWithout($capability, 'capability_hash'), $capability['capability_hash']);
            $this->assertFalse($capability['agent_invocable']);
            $this->assertFalse($capability['model_invocation']);
            $this->assertFalse($capability['external_egress']);
        }

        $this->assertSame(
            ['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'],
            array_column($registry['entrypoint_bindings'], 'entrypoint_type')
        );
        $this->assertSame(
            [SeoRoleCapabilityRegistry::BACKEND_ID],
            array_values(array_unique(array_column($registry['entrypoint_bindings'], 'backend_id')))
        );
        $this->assertNotContains('seo.search_submission', array_merge(...array_column($roles, 'tool_allowlist')));
    }

    public function test_prompt_policy_schema_and_manifest_hashes_are_recomputable(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $prompts = app(SeoPromptRegistry::class)->definitions();
        $policies = app(SeoPolicyRegistry::class)->definitions();
        $schemas = app(SeoRoleCapabilityRegistry::class)->schemas();

        foreach ($prompts as $definition) {
            $this->assertSame($definition['hash'], $hasher->promptHash((string) file_get_contents(base_path('../'.$definition['path']))));
        }
        foreach (array_merge($policies, $schemas) as $definition) {
            $payload = $this->jsonFile(base_path('../'.$definition['path']));
            $this->assertSame($definition['hash'], $hasher->hash($payload));
        }

        $promptManifest = $this->jsonFile(base_path('docs/seo/generated/seo-agent-prompt-manifest.v1.json'));
        $policyManifest = $this->jsonFile(base_path('docs/seo/generated/seo-agent-policy-manifest.v1.json'));
        $this->assertSame($prompts, $promptManifest['prompts']);
        $this->assertSame($policies, $policyManifest['policies']);
        $this->assertSame($hasher->hashWithout($promptManifest, 'manifest_hash'), $promptManifest['manifest_hash']);
        $this->assertSame($hasher->hashWithout($policyManifest, 'manifest_hash'), $policyManifest['manifest_hash']);

        $release = $this->jsonFile(resource_path('seo-agent/policies/seo.release_separation.v1.json'));
        $this->assertSame('contract_only', $release['classification']);
        $this->assertFalse($release['execution_authorized']);
        $this->assertSame('seo.policy_gateway', $release['future_consumer']);
        $this->assertCount(15, $release['gate_order']);
        foreach ($release['negative_guarantees'] as $guarantee) {
            $this->assertFalse($guarantee);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
