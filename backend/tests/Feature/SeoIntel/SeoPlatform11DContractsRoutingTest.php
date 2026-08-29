<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Routing\CouncilConflictResolver;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;
use Tests\TestCase;

final class SeoPlatform11DContractsRoutingTest extends TestCase
{
    public function test_contract_manifest_is_canonical_and_reuses_the_11c_action_manifest(): void
    {
        $registry = app(CouncilContractRegistry::class);
        $manifest = $registry->manifest();
        $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-council-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(10, $manifest['contracts']);
        $this->assertTrue($registry->verify($artifact));
        $this->assertSame('seo.action_scoped_manifest.v1', $manifest['reused_action_manifest']['id']);
        $this->assertSame('backend/resources/seo-agent/policy-gateway/schemas/seo.action_scoped_manifest.v1.schema.json', $manifest['reused_action_manifest']['path']);
        $this->assertFalse($manifest['negative_guarantees']['second_action_manifest']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['manifest_hash']);
    }

    public function test_binding_matches_the_frozen_registry_and_contains_no_write_capability(): void
    {
        $registry = app(RoleCapabilityBindingRegistry::class);
        $binding = $registry->binding();
        $bound = array_merge(...array_values($binding['roles']));

        $this->assertSame('READY', $registry->status());
        $this->assertCount(9, $binding['roles']);
        $this->assertSame([], array_values(array_intersect($bound, $binding['prohibited_capabilities'])));
        $this->assertSame('1.0.0', $registry->reference()['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $registry->reference()['hash']);
        foreach (['seo.cms_writer', 'seo.search_submission', 'seo.url_truth_writer', 'career.current_merger', 'career.page_assembly_import'] as $writeCapability) {
            $this->assertNotContains($writeCapability, $bound);
        }
    }

    public function test_golden_corpus_closes_with_exact_routing_and_unknown_metrics_are_not_observed(): void
    {
        $metrics = app(GoldenRoutingEvaluator::class)->evaluate();
        $corpus = json_decode((string) file_get_contents(resource_path('seo-agent/council/routing/seo.council_golden_routing.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $fixtures = $corpus['fixtures'];
        $families = array_values(array_unique(array_column($fixtures, 'family')));
        $locales = array_values(array_unique(array_column($fixtures, 'locale')));
        $evidenceTypes = array_values(array_unique(array_merge(...array_column($fixtures, 'evidence_types'))));
        sort($families);
        sort($locales);
        sort($evidenceTypes);

        $this->assertSame(['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed'], $metrics['routing_precision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metrics['corpus_hash']);
        $this->assertSame(['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed'], $metrics['routing_recall']);
        $this->assertSame(0, $metrics['missed_required_mode_rate']['numerator']);
        $this->assertSame(0, $metrics['unnecessary_mode_rate']['numerator']);
        $this->assertSame(1, $metrics['all_team_invocation_count']['numerator']);
        $this->assertSame(0, $metrics['unauthorized_all_team_invocation_count']['numerator']);
        $this->assertSame('not_observed', $metrics['human_route_correction_rate']['measurement_state']);
        $this->assertNull($metrics['human_route_correction_rate']['numerator']);
        $this->assertSame('not_observed', $metrics['routing_latency']['measurement_state']);
        $this->assertSame(['articles_topics', 'career', 'other_public', 'personality', 'tests', 'trust_method_help'], $families);
        $this->assertSame(['en', 'zh-CN'], $locales);
        $this->assertSame([
            'authority_parity', 'cache_projection', 'career_candidate', 'career_manifest_validation',
            'competitor_public', 'content_claim', 'duplicate', 'entity', 'funnel_aggregate',
            'gateway_competitor_public', 'lifecycle', 'release_separation', 'runtime_health',
            'search_measurement', 'stability',
        ], $evidenceTypes);
    }

    public function test_conflict_resolution_uses_the_fixed_priority_and_holds_ambiguous_equal_priority_evidence(): void
    {
        $resolver = app(CouncilConflictResolver::class);
        $resolved = $resolver->resolve(str_repeat('1', 64), 'private_safety_authority', 'cro', true, false);
        $unresolved = $resolver->resolve(str_repeat('2', 64), 'claim_evidence', 'claim_evidence', false, true);

        $this->assertSame('private_safety_authority', $resolved['winner']);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertFalse($resolved['execution_allowed']);
        $this->assertSame('unresolved_conflict', $unresolved['status']);
        $this->assertNull($unresolved['winner']);
        $this->assertTrue($unresolved['human_decision_required']);
        $this->assertFalse($unresolved['execution_allowed']);
    }

    public function test_runtime_snapshot_is_deployed_disabled_and_career_is_unavailable(): void
    {
        $snapshot = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();

        $this->assertSame('DEPLOYED_DISABLED', $snapshot['orchestrator_state']);
        $this->assertSame('DETERMINISTIC_ROUTE_HOLD_ONLY', $snapshot['runtime_mode']);
        $this->assertSame('unavailable_manifest_validator_risk_open', $snapshot['career_runtime']);
        $this->assertSame('dormant_not_authorized', $snapshot['l4']);
        $this->assertFalse($snapshot['mission_execution_enabled']);
        $this->assertFalse($snapshot['execution_allowed']);
        foreach (['model_calls', 'tool_calls', 'external_calls', 'agent_write_permissions', 'active_manifests', 'trusted_signing_keys'] as $field) {
            $this->assertSame(0, $snapshot[$field], $field);
        }
    }
}
