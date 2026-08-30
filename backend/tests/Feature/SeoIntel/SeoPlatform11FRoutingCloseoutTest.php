<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementCloseoutBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementFixtureEvaluator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11FRoutingCloseoutTest extends TestCase
{
    public function test_existing_binding_routes_bounded_analytics_and_cro_to_one_non_delegating_mode(): void
    {
        $binding = app(RoleCapabilityBindingRegistry::class);
        $mission = $binding->mission('bounded_review');

        $this->assertSame(1, $mission['max_modes']);
        $this->assertFalse($mission['allow_delegation']);
        $this->assertSame(['seo.expert.search_analytics_measurement'], $binding->selectorVariant($mission, 'analytics')['eligible_roles']);
        $this->assertSame(['seo.expert.commercial_funnel_cro'], $binding->selectorVariant($mission, 'cro')['eligible_roles']);
        $this->assertCount(1, glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []);
        $this->assertSame('655d25e227e33f08dc8e8589a414a6a755572450bb9f7da740f7b5d47df40a73', hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')));
    }

    public function test_fixture_metrics_and_candidate_closeout_are_exact_sha_bound_and_zero_write(): void
    {
        $fixture = app(MeasurementFixtureEvaluator::class)->evaluate();
        $this->assertSame(24, $fixture['metrics']['fixture_total']);
        foreach ([
            'false_positive', 'false_negative', 'source_state_misclassification_count',
            'measurement_state_misclassification_count', 'valid_zero_misclassification_count',
            'gai_capability_invention_count', 'causal_overclaim_count', 'attribution_overclaim_count',
            'private_data_leak_count', 'private_url_leak_count', 'production_metric_override_count',
            'policy_bypass_count', 'role_expansion_bypass_count', 'write_attempt_count',
        ] as $field) {
            $this->assertSame(0, $fixture['metrics'][$field], $field);
        }

        $sha = $this->gitSha('HEAD');
        $receipt = app(MeasurementCloseoutBuilder::class)->build($sha, 'ci_candidate', $this->gitSha('HEAD^'));
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['mode_state']);
        $this->assertSame('READY', $receipt['dependency_status']);
        $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11F']);
        $this->assertFalse($receipt['ready_for_11G']);
        $this->assertSame('manual_export_only', $receipt['gai_capability_state']);
        foreach (['model_calls', 'tool_calls', 'external_calls', 'production_metric_override_count', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'active_manifest_count', 'trusted_key_count', 'production_permissions'] as $field) {
            $this->assertSame(0, $receipt[$field], $field);
        }
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
    }

    private function gitSha(string $revision): string
    {
        $process = new Process(['git', 'rev-parse', $revision], dirname(base_path()));
        $process->mustRun();

        return trim($process->getOutput());
    }
}
