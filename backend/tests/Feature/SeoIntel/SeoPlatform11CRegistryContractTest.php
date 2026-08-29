<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayContractRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use Tests\TestCase;

final class SeoPlatform11CRegistryContractTest extends TestCase
{
    public function test_registry_binds_frozen_dependencies_and_all_production_guards_are_disabled(): void
    {
        $authority = app(PolicyGatewayRegistry::class);
        $registry = $authority->registry();

        $this->assertSame('READY', $authority->dependencyStatus());
        $this->assertSame('fermatmind.seo.policy_gateway_registry', $registry['registry_id']);
        $this->assertSame('1.0.0', $registry['registry_version']);
        $this->assertSame('frozen_deny_only', $registry['registry_state']);
        $this->assertSame('dormant_not_authorized', $registry['runtime_state']);
        $this->assertSame(PolicyGatewayRegistry::ROLE_REGISTRY_HASH, $registry['dependencies']['role_capability_registry']['hash']);
        $this->assertSame(PolicyGatewayRegistry::EVIDENCE_MANIFEST_HASH, $registry['dependencies']['evidence_contract_manifest']['hash']);
        $this->assertSame(PolicyGatewayRegistry::PAGE_FAMILY_POLICY_HASH, $registry['dependencies']['page_family_policy']['hash']);
        $this->assertSame(PolicyGatewayRegistry::RELEASE_SEPARATION_POLICY_HASH, $registry['dependencies']['release_separation_policy']['hash']);

        $this->assertSame([
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'post12_agent_write_enabled' => false,
            'l4_state' => 'dormant_not_authorized',
            'agent_default_write_permission' => false,
            'deterministic_system_final_veto' => true,
            'global_write_gate' => false,
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'model_invocation_enabled' => false,
            'tool_invocation_enabled' => false,
            'external_egress_enabled' => false,
        ], $registry['guards']);
        $this->assertSame([], $authority->trustRegistry()['trusted_public_keys']);
        $this->assertSame([], $authority->trustRegistry()['active_manifest_ids']);
    }

    public function test_machine_contracts_are_strict_and_decision_has_no_allow_enum(): void
    {
        foreach (glob(resource_path('seo-agent/policy-gateway/schemas/*.json')) ?: [] as $path) {
            $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($schema['additionalProperties'], $path);
        }
        $decision = json_decode((string) file_get_contents(resource_path('seo-agent/policy-gateway/schemas/seo.policy_decision.v1.schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['HOLD', 'DENY'], $decision['properties']['decision']['enum']);
        $this->assertNotContains('ALLOW', $decision['properties']['decision']['enum']);

        $manifest = app(PolicyGatewayContractRegistry::class)->manifest();
        $projection = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-policy-gateway-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($manifest, $projection);
        $this->assertTrue(app(PolicyGatewayContractRegistry::class)->verify($projection));
    }
}
