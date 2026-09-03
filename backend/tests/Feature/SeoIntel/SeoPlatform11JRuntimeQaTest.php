<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11JCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11MissionValidator;
use App\Services\SeoCouncil\Platform11\RuntimeQaRunner;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11JRuntimeQaTest extends TestCase
{
    public function test_http_200_requires_complete_hash_revision_and_visible_readback_parity(): void
    {
        $runner = $this->app->make(RuntimeQaRunner::class);
        $base = $this->input();
        $cases = [
            [[...$base, 'transport_http_status' => 503], 'TRANSPORT_FAILED'],
            [[...$base, 'visible_content' => false], 'VISIBLE_CONTENT_MISSING'],
            [[...$base, 'observed_deployment_sha' => str_repeat('f', 40)], 'DEPLOYMENT_SHA_MISMATCH'],
            [[...$base, 'authority_revision' => str_repeat('f', 64)], 'AUTHORITY_REVISION_MISMATCH'],
            [[...$base, 'cms_readback_hash' => str_repeat('f', 64)], 'CMS_READBACK_MISMATCH'],
            [[...$base, 'cache_source_hash' => str_repeat('f', 64)], 'CACHE_SOURCE_MISMATCH'],
            [[...$base, 'canonical_parity' => false], 'CANONICAL_DRIFT'],
            [[...$base, 'robots_parity' => false], 'ROBOTS_DRIFT'],
            [[...$base, 'schema_parity' => false], 'SCHEMA_DRIFT'],
            [[...$base, 'feed_membership_parity' => false], 'FEED_DRIFT'],
            [[...$base, 'rollback_receipt_present' => false], 'ROLLBACK_RECEIPT_MISSING'],
        ];
        foreach ($cases as [$input, $finding]) {
            $result = $runner->evaluate($input, $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
            $this->assertSame('HOLD', $result['output']['status']);
            $this->assertContains($finding, array_column($result['output']['findings'], 'code'));
            $this->assertSame('technical_validation_failed', $result['output']['attribution_assessment']['classification']);
            $this->assertFalse($result['output']['execution_allowed']);
        }
    }

    public function test_attribution_requires_preregistration_scope_window_measurement_and_ledger(): void
    {
        $runner = $this->app->make(RuntimeQaRunner::class);
        $supported = $runner->evaluate($this->input(), $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('technically_valid_and_attribution_supported', $supported['output']['attribution_assessment']['classification']);
        $this->assertTrue($supported['output']['attribution_assessment']['causality_supported']);

        foreach (['tracking_changed', 'google_update_window', 'seasonal_confounder', 'single_observation'] as $field) {
            $input = $this->input();
            $input['experiment'][$field] = true;
            $result = $runner->evaluate($input, $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
            $this->assertSame('technically_valid_but_causality_unproven', $result['output']['attribution_assessment']['classification']);
            $this->assertFalse($result['output']['attribution_assessment']['causality_supported']);
        }
        $input = $this->input();
        $input['experiment']['measurement_valid'] = false;
        $measurement = $runner->evaluate($input, $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('measurement_hold', $measurement['output']['attribution_assessment']['classification']);

        $refs = $this->refs();
        $refs[0]['status'] = 'DEPENDENCY_HOLD';
        $dependency = $runner->evaluate($this->input(), $refs, str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('dependency_hold', $dependency['output']['attribution_assessment']['classification']);

        $missing = $this->refs();
        array_pop($missing);
        $dependency = $runner->evaluate($this->input(), $missing, str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('dependency_hold', $dependency['output']['attribution_assessment']['classification']);
    }

    public function test_rollback_classes_are_deterministic_and_never_execute_in_platform_11(): void
    {
        $runner = $this->app->make(RuntimeQaRunner::class);
        foreach ([1 => 'meta_description', 2 => 'body', 3 => 'canonical'] as $class => $action) {
            $result = $runner->evaluate([...$this->input(), 'action_type' => $action], $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
            $rollback = $result['output']['rollback_classification'];
            $this->assertSame($class, $rollback['class']);
            $this->assertFalse($rollback['rollback_executed']);
            $this->assertFalse($rollback['execution_allowed']);
            $this->assertSame($class >= 2, $rollback['human_decision_required']);
        }
    }

    public function test_runtime_qa_uses_l0_stability_role_and_rejects_write_requests(): void
    {
        $binding = $this->app->make(Platform11ContractRegistry::class)->binding();
        $boundedReview = collect($binding['missions'])->firstWhere('mission_id', 'bounded_review');
        $runtime = collect($boundedReview['selector']['variants'])->firstWhere('value', 'runtime_qa');
        $this->assertSame([
            'runtime_health', 'cms_readback', 'cache_projection', 'canonical', 'robots',
            'schema', 'feed', 'rollback_receipt', 'experiment_ledger',
        ], $runtime['required_evidence']);

        $validator = $this->app->make(Platform11MissionValidator::class);
        $valid = $this->request();
        $this->assertSame($valid, $validator->validate($valid));
        $numericHashes = $valid;
        $numericHashes['mode_input']['expected_deployment_sha'] = str_repeat('1', 40);
        $numericHashes['mode_input']['observed_deployment_sha'] = str_repeat('1', 40);
        $numericHashes['mode_input']['expected_authority_revision'] = str_repeat('2', 64);
        $numericHashes['mode_input']['authority_revision'] = str_repeat('2', 64);
        $this->assertSame($numericHashes, $validator->validate($numericHashes));
        foreach (['execute_rollback', 'cms_write', 'url_truth_write', 'search_write'] as $field) {
            try {
                $validator->validate([...$valid, 'mode_input' => [...$valid['mode_input'], $field => true]]);
                $this->fail($field.' accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $receipt = $this->app->make(ApiMissionAdapter::class)->submit($valid);
        $this->assertSame('runtime_qa', $receipt['review_domain']);
        $this->assertSame('L0', $receipt['autonomy']);
        $this->assertSame('seo.expert.public_content_stability', $receipt['role_id']);
        $this->assertSame('seo.runtime_qa_readback_attribution', $receipt['route_plan'][0]['capability_id']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    public function test_closeout_observes_all_negative_probes_and_stays_read_only(): void
    {
        $sha = str_repeat('c', 40);
        $h = $this->app->make(Platform11HCloseoutBuilder::class)->build($sha, 'ci_candidate');
        $i = $this->app->make(Platform11ICloseoutBuilder::class)->build($sha, 'ci_candidate', $h);
        $receipt = $this->app->make(Platform11JCloseoutBuilder::class)->build($sha, 'ci_candidate', $i);

        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame($receipt['negative_probes']['total'], $receipt['negative_probes']['passed']);
        $this->assertSame(0, $receipt['negative_probes']['bypass_count']);
        foreach (['model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions'] as $field) {
            $this->assertSame(0, $receipt[$field]);
        }
        $this->assertFalse($receipt['ready_for_11K']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11j:test', 'idempotency_key' => 'mission:11j:test',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'runtime_qa',
            'requested_role' => null, 'evidence_bundle_refs' => $this->refs(), 'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'mode_input' => $this->input(),
        ];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'transport_http_status' => 200, 'expected_deployment_sha' => str_repeat('a', 40), 'observed_deployment_sha' => str_repeat('a', 40),
            'expected_authority_revision' => str_repeat('b', 64), 'authority_revision' => str_repeat('b', 64),
            'expected_cms_readback_hash' => str_repeat('b', 64), 'cms_readback_hash' => str_repeat('b', 64),
            'expected_cache_source_hash' => str_repeat('b', 64), 'cache_source_hash' => str_repeat('b', 64),
            'visible_content' => true, 'canonical_parity' => true, 'robots_parity' => true, 'schema_parity' => true,
            'feed_membership_parity' => true, 'locale_parity' => true, 'rollback_receipt_present' => true,
            'experiment' => [
                'preregistered' => true, 'exposure_scope_hash' => str_repeat('c', 64),
                'window_start' => '2026-09-01T00:00:00Z', 'window_end' => '2026-09-02T00:00:00Z',
                'measurement_valid' => true, 'ledger_hash' => str_repeat('d', 64), 'tracking_changed' => false,
                'google_update_window' => false, 'seasonal_confounder' => false, 'single_observation' => false,
            ],
            'action_type' => 'meta_description', 'preapproved' => true, 'single_public_target' => true,
            'low_risk' => true, 'reversible' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return array_map(static fn (string $type): array => [
            'bundle_id' => 'bundle:11j:'.$type, 'bundle_version' => 1, 'bundle_hash' => str_repeat('e', 64),
            'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => str_repeat('f', 64),
        ], ['runtime_health', 'cms_readback', 'cache_projection', 'canonical', 'robots', 'schema', 'feed', 'rollback_receipt', 'experiment_ledger']);
    }
}
