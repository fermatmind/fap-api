<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeoPlatform11DBindingV2Test extends TestCase
{
    public function test_v1_bytes_and_historical_hash_are_frozen_and_v2_hash_recomputes_independently(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $v1Path = resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json');
        $v1 = json_decode((string) file_get_contents($v1Path), true, 512, JSON_THROW_ON_ERROR);
        $binding = app(RoleCapabilityBindingRegistry::class)->binding();
        $v2Path = resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json');
        $schema = json_decode((string) file_get_contents(resource_path('seo-agent/council/schemas/seo.role_capability_binding.v3.schema.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(RoleCapabilityBindingRegistry::V1_FILE_SHA256, hash_file('sha256', $v1Path));
        $this->assertSame(RoleCapabilityBindingRegistry::V2_FILE_SHA256, hash_file('sha256', $v2Path));
        $this->assertSame('64afc23a9e61ccdee1e0551a5ac323a2fc2bbcc78abbcbd5799c84eaf979b9b0', $hasher->hash($v1));
        $this->assertSame('3.0.0', $binding['binding_version']);
        $this->assertSame($binding['binding_hash'], $hasher->hashWithout($binding, 'binding_hash'));
        $this->assertCount(7, $binding['missions']);
        foreach ($binding['deterministic_tool_registry'] as $tool) {
            $this->assertSame('deterministic_internal_service', $tool['execution_kind']);
            $this->assertFalse($tool['agent_tool_invocation']);
            $this->assertFalse($tool['execution_allowed']);
            $this->assertSame([], $tool['write_permissions']);
        }
        $this->assertSame([
            'mission_id', 'mission_version', 'admission_role', 'required_capabilities', 'eligible_roles',
            'deterministic_tools', 'required_evidence', 'reviewer_requirement', 'max_modes',
            'allowed_page_families', 'allowed_locales', 'authority_ceiling', 'stop_conditions',
            'execution_allowed', 'allow_delegation', 'route_rule',
        ], $schema['$defs']['mission']['required']);
    }

    #[DataProvider('invalidBindingProvider')]
    public function test_binding_schema_and_semantic_expansions_fail_closed(callable $mutate, string $metric): void
    {
        $registry = app(RoleCapabilityBindingRegistry::class);
        $candidate = $mutate($registry->binding());
        $report = $registry->validationReport($candidate);

        $this->assertFalse($report['valid']);
        $this->assertGreaterThan(0, $report[$metric]);
    }

    public static function invalidBindingProvider(): array
    {
        return [
            'unregistered mission' => [static function (array $binding): array {
                $binding['missions'][0]['mission_id'] = 'unknown_mission';

                return $binding;
            }, 'unbound_mission_count'],
            'unregistered role' => [static function (array $binding): array {
                $binding['missions'][0]['eligible_roles'][0] = 'seo.expert.unknown';

                return $binding;
            }, 'unknown_role_count'],
            'prohibited capability' => [static function (array $binding): array {
                $binding['missions'][0]['required_capabilities'][] = 'seo.cms_writer';

                return $binding;
            }, 'unknown_capability_count'],
            'unregistered deterministic tool' => [static function (array $binding): array {
                $binding['missions'][0]['deterministic_tools'][] = 'seo.unregistered_tool';

                return $binding;
            }, 'unknown_tool_count'],
            'missing required evidence' => [static function (array $binding): array {
                $binding['missions'][0]['required_evidence'] = [];

                return $binding;
            }, 'binding_schema_probe_failed'],
            'missing reviewer' => [static function (array $binding): array {
                $binding['missions'][0]['reviewer_requirement'] = '';

                return $binding;
            }, 'binding_schema_probe_failed'],
            'max modes expansion' => [static function (array $binding): array {
                $binding['missions'][0]['max_modes'] = 7;

                return $binding;
            }, 'binding_schema_probe_failed'],
            'page family expansion' => [static function (array $binding): array {
                $binding['missions'][0]['allowed_page_families'][] = 'private';

                return $binding;
            }, 'binding_schema_probe_failed'],
            'locale expansion' => [static function (array $binding): array {
                $binding['missions'][0]['allowed_locales'][] = 'fr';

                return $binding;
            }, 'binding_schema_probe_failed'],
            'missing stop condition' => [static function (array $binding): array {
                $binding['missions'][0]['stop_conditions'] = [];

                return $binding;
            }, 'binding_schema_probe_failed'],
            'content drift without version or hash' => [static function (array $binding): array {
                $binding['missions'][0]['reviewer_requirement'] = 'changed_without_version_or_hash';

                return $binding;
            }, 'binding_hash_drift_count'],
        ];
    }

    public function test_requested_role_and_career_family_cannot_expand_binding_scope(): void
    {
        $requestedRole = $this->request('bounded_review', 'tests', 'analytics', ['search_measurement']);
        $requestedRole['requested_role'] = 'seo.expert.technical_search_authority';
        try {
            app(ApiMissionAdapter::class)->submit($requestedRole);
            $this->fail('Expected requested role expansion to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('REQUESTED_ROLE_EXPANSION_DENIED', $exception->getMessage());
        }

        $career = $this->request('career_candidate_generation', 'tests', null, ['career_candidate']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MISSION_REQUEST_SCOPE_INVALID');
        app(ApiMissionAdapter::class)->submit($career);
    }

    /** @param list<string> $evidenceTypes @return array<string, mixed> */
    private function request(string $mission, string $family, ?string $domain, array $evidenceTypes): array
    {
        $refs = [];
        foreach ($evidenceTypes as $index => $type) {
            $refs[] = [
                'bundle_id' => 'bundle:binding:'.$index,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'binding:'.$index),
                'evidence_type' => $type,
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ];
        }

        return [
            'mission_id' => 'mission:binding:'.$mission,
            'idempotency_key' => 'idempotency:binding:'.$mission,
            'mission_type' => $mission,
            'family' => $family,
            'locale' => 'zh-CN',
            'review_domain' => $domain,
            'requested_role' => null,
            'evidence_bundle_refs' => $refs,
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }
}
