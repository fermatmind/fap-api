<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Platform11\IndependentReviewRunner;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11JCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11KCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11MissionValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11KIndependentReviewTest extends TestCase
{
    public function test_mode_has_one_independent_role_three_verdicts_and_no_access(): void
    {
        $mode = $this->app->make(Platform11ContractRegistry::class)->independentReviewMode();
        $this->assertSame('seo.independent_reviewer', $mode['role_id']);
        $this->assertSame(['recommend_approve', 'hold', 'reject'], $mode['verdict_enum']);
        $this->assertSame('seo.independent_review.v1', $mode['prompt_namespace']);
        foreach (['tool_allowlist', 'egress_allowlist', 'write_permissions'] as $field) {
            $this->assertSame([], $mode[$field]);
        }
        foreach (['cms_access', 'deploy_access', 'url_truth_access', 'search_access', 'allow_delegation', 'model_invocation', 'tool_invocation', 'external_egress', 'execution_allowed'] as $field) {
            $this->assertFalse($mode[$field]);
        }
    }

    public function test_runner_uses_only_frozen_hashes_and_emits_exactly_three_business_verdicts(): void
    {
        $runner = $this->app->make(IndependentReviewRunner::class);
        $pass = $runner->evaluate($this->input(), $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('recommend_approve', $pass['output']['verdict']);
        $this->assertSame('HOLD', $pass['output']['policy_gateway_decision']);
        $this->assertFalse($pass['output']['execution_allowed']);

        foreach (['HOLD' => 'hold', 'REJECT' => 'reject'] as $state => $verdict) {
            $input = $this->input();
            $input['frozen_manifest']['safety_review'] = $state;
            $input['frozen_manifest'] = $this->hashManifest($input['frozen_manifest']);
            $result = $runner->evaluate($input, $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
            $this->assertSame($verdict, $result['output']['verdict']);
        }
    }

    public function test_reused_identity_mutable_manifest_and_hash_drift_are_rejected(): void
    {
        $runner = $this->app->make(IndependentReviewRunner::class);
        $run = str_repeat('a', 64);
        $context = str_repeat('b', 64);
        foreach ([
            [...$this->input(), 'generation_run_id' => $run],
            [...$this->input(), 'generation_context_id' => $context],
            [...$this->input(), 'frozen_manifest' => [...$this->input()['frozen_manifest'], 'frozen' => false]],
            [...$this->input(), 'candidate_artifact_hash' => str_repeat('f', 64)],
        ] as $input) {
            $result = $runner->evaluate($input, $this->refs(), $run, $context);
            $this->assertSame('reject', $result['output']['verdict']);
            $this->assertFalse($result['output']['execution_allowed']);
        }
    }

    public function test_validator_rejects_generation_context_tools_and_fourth_verdict_injection(): void
    {
        $validator = $this->app->make(Platform11MissionValidator::class);
        $valid = $this->request();
        $this->assertSame($valid, $validator->validate($valid));
        foreach (['generation_prompt', 'hidden_reasoning', 'full_trace', 'fourth_verdict', 'cms_access', 'deploy_access', 'url_truth_access', 'search_access'] as $field) {
            try {
                $validator->validate([...$valid, 'mode_input' => [...$valid['mode_input'], $field => true]]);
                $this->fail($field.' accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['cms'], ['deploy'], ['url_truth'], ['search']] as $scope) {
            try {
                $validator->validate([...$valid, 'tool_scope' => $scope]);
                $this->fail('Tool scope accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_orchestrator_creates_isolated_ids_and_policy_vetoes_recommendation(): void
    {
        $input = $this->input();
        $receipt = $this->app->make(ApiMissionAdapter::class)->submit($this->request($input));
        $this->assertSame('independent_registry_review', $receipt['review_domain']);
        $this->assertSame('seo.independent_reviewer', $receipt['role_id']);
        $this->assertSame('seo.independent_policy_experiment_safety_review', $receipt['route_plan'][0]['capability_id']);
        $this->assertNotSame($input['generation_run_id'], $receipt['run_id']);
        $this->assertNotSame($input['generation_context_id'], $receipt['context_id']);
        $this->assertSame('seo.independent_review.v1', $receipt['mode_receipt']['prompt_namespace']);
        $this->assertSame('recommend_approve', $receipt['mode_output']['verdict']);
        $this->assertSame('POLICY_HOLD', $receipt['status']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    public function test_closeout_observes_every_independence_probe_and_stays_read_only(): void
    {
        $sha = str_repeat('c', 40);
        $h = $this->app->make(Platform11HCloseoutBuilder::class)->build($sha, 'ci_candidate');
        $i = $this->app->make(Platform11ICloseoutBuilder::class)->build($sha, 'ci_candidate', $h);
        $j = $this->app->make(Platform11JCloseoutBuilder::class)->build($sha, 'ci_candidate', $i);
        $receipt = $this->app->make(Platform11KCloseoutBuilder::class)->build($sha, 'ci_candidate', $j);

        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame($receipt['negative_probes']['total'], $receipt['negative_probes']['passed']);
        $this->assertSame(0, $receipt['negative_probes']['bypass_count']);
        foreach (['run_id_reuse_count', 'context_reuse_count', 'generation_context_inheritance_count', 'hidden_reasoning_ingestion_count', 'mutable_manifest_acceptance_count', 'forbidden_tool_exposure_count', 'verdict_enum_violation_count', 'policy_approve_bypass_count', 'model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'deploy_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions'] as $field) {
            $this->assertSame(0, $receipt[$field]);
        }
        $this->assertFalse($receipt['ready_for_11L']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    /** @param array<string, mixed>|null $input @return array<string, mixed> */
    private function request(?array $input = null): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11k:test', 'idempotency_key' => 'mission:11k:test',
            'mission_type' => 'independent_registry_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => null,
            'requested_role' => null, 'evidence_bundle_refs' => $this->refs(), 'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'mode_input' => $input ?? $this->input(),
        ];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $manifest = $this->hashManifest([
            'manifest_id' => 'artifact:11k:test', 'manifest_version' => 1, 'frozen' => true,
            'candidate_artifact_hash' => str_repeat('c', 64),
            'policy_review' => 'PASS', 'experiment_review' => 'PASS', 'safety_review' => 'PASS',
        ]);

        return [
            'generation_run_id' => str_repeat('d', 64), 'generation_context_id' => str_repeat('e', 64),
            'frozen_manifest' => $manifest, 'candidate_artifact_hash' => str_repeat('c', 64),
            'policy_ref' => ['id' => 'policy:v2', 'version' => '2.0.0', 'hash' => str_repeat('1', 64)],
            'registry_ref' => ['id' => 'registry:v2', 'version' => '2.0.0', 'hash' => str_repeat('2', 64)],
            'binding_ref' => ['id' => 'binding:v4', 'version' => '4.0.0', 'hash' => str_repeat('3', 64)],
        ];
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function hashManifest(array $manifest): array
    {
        unset($manifest['manifest_hash']);
        $manifest['manifest_hash'] = $this->app->make(SeoRegistryHasher::class)->hash($manifest);

        return $manifest;
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return [[
            'bundle_id' => 'bundle:11k:frozen', 'bundle_version' => 1, 'bundle_hash' => str_repeat('4', 64),
            'evidence_type' => 'frozen_artifact', 'status' => 'READY', 'authority_revision' => str_repeat('5', 64),
        ]];
    }
}
