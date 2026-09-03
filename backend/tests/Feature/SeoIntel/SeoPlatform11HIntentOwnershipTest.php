<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\Platform11\IntentOwnershipRunner;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11MissionValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11HIntentOwnershipTest extends TestCase
{
    public function test_governance_v2_is_frozen_deny_only_and_keeps_nine_roles(): void
    {
        $contracts = $this->app->make(Platform11ContractRegistry::class);
        $registry = $contracts->registry();
        $binding = $contracts->binding();
        $policy = $contracts->policy();

        $this->assertSame('2.0.0', $registry['registry_version']);
        $this->assertSame(9, count($registry['roles']));
        $this->assertSame(1, count(array_filter($registry['roles'], static fn (array $role): bool => $role['role_id'] === 'seo.orchestrator')));
        $new = array_values(array_filter($registry['capabilities'], static fn (array $capability): bool => str_starts_with($capability['capability_id'], 'seo.') && in_array($capability['capability_id'], [
            'seo.intent_query_ownership', 'seo.content_claim_entity_audit', 'seo.editorial_cms_draft',
            'seo.internal_link_recommendation', 'seo.runtime_qa_readback_attribution',
            'seo.independent_policy_experiment_safety_review',
        ], true)));
        $this->assertCount(6, $new);
        foreach ($new as $capability) {
            $this->assertFalse($capability['agent_invocable']);
            $this->assertSame([], $capability['write_permissions']);
            $this->assertFalse($capability['external_egress']);
            $this->assertFalse($capability['model_invocation']);
            $this->assertFalse($capability['allow_delegation']);
            $this->assertFalse($capability['execution_allowed']);
        }
        $this->assertSame('4.0.0', $binding['binding_version']);
        $this->assertSame('2.0.0', $policy['registry_version']);
        foreach (['global_write_gate', 'model_invocation_enabled', 'tool_invocation_enabled', 'external_egress_enabled', 'post12_agent_write_enabled'] as $guard) {
            $this->assertFalse($policy['guards'][$guard]);
        }
        $this->assertSame(0, $policy['guards']['active_manifest_count']);
        $this->assertSame(0, $policy['guards']['trusted_signing_key_count']);
        $this->assertTrue($contracts->verifyGenerated());
    }

    public function test_legacy_registry_binding_policy_and_prompts_remain_byte_frozen(): void
    {
        $files = [
            'docs/seo/generated/seo-agent-role-capability-registry.v1.json' => Platform11ContractRegistry::REGISTRY_V1_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v1.json' => Platform11ContractRegistry::BINDING_V1_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v2.json' => Platform11ContractRegistry::BINDING_V2_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v3.json' => Platform11ContractRegistry::BINDING_V3_FILE_SHA256,
            'resources/seo-agent/policy-gateway/seo.policy_gateway_registry.v1.json' => Platform11ContractRegistry::POLICY_V1_FILE_SHA256,
        ];
        foreach ($files as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
    }

    public function test_mission_v2_enforces_domain_autonomy_role_and_empty_scopes(): void
    {
        $validator = $this->app->make(Platform11MissionValidator::class);
        $valid = $this->request();
        $this->assertSame($valid, $validator->validate($valid));

        foreach ([
            [...$valid, 'autonomy' => 'L0'],
            [...$valid, 'autonomy' => 'L2'],
            [...$valid, 'requested_role' => 'seo.orchestrator'],
            [...$valid, 'tool_scope' => ['cms']],
            [...$valid, 'egress_scope' => ['https://example.test']],
        ] as $invalid) {
            try {
                $validator->validate($invalid);
                $this->fail('Scope expansion was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_intent_runner_abstains_for_conflict_missing_cross_locale_and_stale_evidence(): void
    {
        $runner = $this->app->make(IntentOwnershipRunner::class);
        $input = $this->request()['mode_input'];
        $refs = $this->request()['evidence_bundle_refs'];
        $cases = [
            [['locale' => 'en', 'status' => 'conflict', 'owner_hashes' => [str_repeat('b', 64), str_repeat('c', 64)], 'issues' => ['multiple_primary_owner'], 'checks' => ['canonical_owner' => 'blocked']], 'MULTIPLE_PRIMARY_OWNER'],
            [['locale' => 'en', 'status' => 'blocked', 'owner_hashes' => [], 'issues' => ['primary_owner_missing'], 'checks' => ['canonical_owner' => 'blocked']], 'PRIMARY_OWNER_MISSING'],
            [['locale' => 'zh-CN', 'status' => 'pass', 'owner_hashes' => [str_repeat('b', 64)], 'issues' => [], 'checks' => ['canonical_owner' => 'pass']], 'LOCALE_AUTHORITY_MISMATCH'],
        ];
        foreach ($cases as [$family, $reason]) {
            $result = $runner->evaluate($input, $family, $refs, str_repeat('d', 64), str_repeat('e', 64));
            $this->assertSame('HOLD', $result['receipt']['status']);
            $this->assertSame($reason, $result['output']['abstain_reason']);
            $this->assertNull($result['output']['primary_owner_candidate']);
        }
        $refs[0]['status'] = 'EVIDENCE_HOLD';
        $stale = $runner->evaluate($input, ['locale' => 'en', 'status' => 'pass', 'owner_hashes' => [str_repeat('b', 64)], 'issues' => [], 'checks' => ['canonical_owner' => 'pass']], $refs, str_repeat('d', 64), str_repeat('e', 64));
        $this->assertSame('EVIDENCE_NOT_READY', $stale['output']['abstain_reason']);
    }

    public function test_intent_runner_emits_only_sanitized_candidate_and_never_executes(): void
    {
        $runner = $this->app->make(IntentOwnershipRunner::class);
        $request = $this->request();
        $result = $runner->evaluate(
            $request['mode_input'],
            ['locale' => 'en', 'status' => 'pass', 'owner_hashes' => [str_repeat('b', 64)], 'issues' => [], 'checks' => ['canonical_owner' => 'pass']],
            $request['evidence_bundle_refs'],
            str_repeat('d', 64),
            str_repeat('e', 64),
        );

        $this->assertSame('PASS', $result['receipt']['status']);
        $this->assertSame(str_repeat('b', 64), $result['output']['primary_owner_candidate']);
        $this->assertFalse($result['output']['execution_allowed']);
        $this->assertSame(0, array_sum($result['receipt']['negative_metrics']));
        $this->assertStringNotContainsString('raw query', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('/account/', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_all_five_entrypoints_only_submit_mission_v2_to_the_single_orchestrator(): void
    {
        $receipts = [];
        foreach ([LocalSkillMissionAdapter::class, CliMissionAdapter::class, ScheduledMissionAdapter::class, ApiMissionAdapter::class, SeoOperationsUiMissionAdapter::class] as $adapter) {
            $receipt = $this->app->make($adapter)->submit($this->request());
            $receipts[] = $receipt;
            $this->assertSame('seo.platform11_run_receipt.v1', $receipt['receipt_version']);
            $this->assertSame('intent_query_ownership', $receipt['review_domain']);
            $this->assertSame('seo.expert.content_entity_quality', $receipt['role_id']);
            $this->assertContains($receipt['role_call_count'], [0, 1]);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertSame(0, $receipt['negative_guarantees']['delegation_count']);
        }
        $this->assertCount(5, array_unique(array_column($receipts, 'caller_type')));
        $this->assertSame(1, count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []));
    }

    public function test_closeout_runs_all_negative_probes_and_does_not_claim_current_production_offline(): void
    {
        $receipt = $this->app->make(Platform11HCloseoutBuilder::class)->build(str_repeat('a', 40), 'ci_candidate');

        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame('prior_production_closeout', $receipt['dependency_snapshot']['source']);
        $this->assertFalse($receipt['dependency_snapshot']['current_candidate_production_claimed']);
        $this->assertSame($receipt['negative_probes']['total'], $receipt['negative_probes']['passed']);
        $this->assertSame(0, $receipt['negative_probes']['bypass_count']);
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['SEO-PLATFORM-11H']);
        $this->assertFalse($receipt['ready_for_11I']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        $refs = [];
        foreach (['query_owner', 'url_truth', 'page_family_policy', 'search_measurement', 'competitive_handoff'] as $type) {
            $refs[] = ['bundle_id' => 'bundle:'.$type, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', $type), 'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => hash('sha256', 'authority:'.$type)];
        }

        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11h:test', 'idempotency_key' => 'mission:11h:test',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'intent_query_ownership',
            'requested_role' => null, 'evidence_bundle_refs' => $refs, 'autonomy' => 'L1',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [],
            'mode_input' => ['query_hmac' => str_repeat('a', 64), 'query_cluster_id' => 'cluster:11h', 'intent_label' => 'career intent', 'query_family_key' => 'career:en', 'locale' => 'en'],
        ];
    }
}
