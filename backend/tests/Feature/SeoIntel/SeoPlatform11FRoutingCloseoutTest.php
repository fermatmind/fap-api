<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoCouncil\Measurement\MeasurementActivityLedger;
use App\Services\SeoCouncil\Measurement\MeasurementCloseoutBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementContractValidator;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceBundleLoader;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceDiagnosticLoader;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceLoadResult;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencyBindingSource;
use Tests\TestCase;

final class SeoPlatform11FRoutingCloseoutTest extends TestCase
{
    public function test_v3_candidate_receipt_is_hash_bound_zero_permission_and_not_a_production_close(): void
    {
        $sha = str_repeat('a', 40);
        $builder = app(MeasurementCloseoutBuilder::class);
        $receipt = $builder->build($sha, 'ci_candidate', $sha);

        $this->assertSame('seo.measurement_closeout.v3', $receipt['receipt_version']);
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['mode_state']);
        $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11F']);
        $this->assertFalse($receipt['ready_for_11G']);
        $this->assertFalse($receipt['production_execution_enabled']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertSame('OFFLINE_NOT_LOADED', $receipt['search_hold_reason']);
        $this->assertSame('OFFLINE_NOT_LOADED', $receipt['cro_hold_reason']);
        foreach (MeasurementContractValidator::zeroMetricFields() as $field) {
            $this->assertSame(0, $receipt[$field], $field);
        }
        $this->assertTrue($builder->verify($receipt, $sha, 'ci_candidate'));
        $tampered = $receipt;
        $tampered['ready_for_11G'] = true;
        $this->assertFalse($builder->verify($tampered, $sha, 'ci_candidate'));
        $this->assertFalse($builder->verify($receipt, str_repeat('b', 40), 'ci_candidate'));
    }

    public function test_runtime_without_real_environment_readmodels_remains_dependency_hold(): void
    {
        $sha = str_repeat('a', 40);
        $receipt = app(MeasurementCloseoutBuilder::class)->build($sha, 'production_runtime', $sha);

        $this->assertSame('DEPENDENCY_HOLD', $receipt['closeout_state']);
        $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11F']);
        $this->assertFalse($receipt['ready_for_11G']);
        $this->assertNotSame('available', $receipt['evidence_source_state']);
    }

    public function test_production_closes_only_with_real_shape_fresh_verified_bundles_and_exact_active_sha(): void
    {
        $sha = str_repeat('a', 40);
        $this->bindRuntimeDependencies();
        $loader = new class implements MeasurementEvidenceBundleLoader, MeasurementEvidenceDiagnosticLoader
        {
            public function loadForScope(string $missionId, string $modeId, string $pageFamily, string $locale, string $environment): array
            {
                return $this->diagnoseForScope($missionId, $modeId, $pageFamily, $locale, $environment)->bundles();
            }

            public function diagnoseForRuntime(string $missionId, string $modeId, string $environment): MeasurementEvidenceLoadResult
            {
                return $this->diagnoseForScope($missionId, $modeId, 'tests', 'en', $environment);
            }

            public function diagnoseForScope(string $missionId, string $modeId, string $pageFamily, string $locale, string $environment): MeasurementEvidenceLoadResult
            {
                $sourceType = $modeId === 'search_measurement' ? 'gsc_aggregate' : 'public_funnel_aggregate';
                $revision = hash('sha256', $modeId.'|'.$environment);
                $gsc = ['clicks' => 10, 'impressions' => 100, 'ctr_ppm' => 100000, 'average_position_milli' => 9000];
                $funnel = [
                    'landing_pv_count' => 200, 'article_to_test_click_count' => 40, 'start_test_count' => 30,
                    'complete_test_count' => 20, 'aggregate_outcome_view_count' => 18, 'return_public_content_count' => 5,
                ];
                $payload = $modeId === 'search_measurement' ? [
                    'windows' => [
                        ['window_days' => 7, 'metrics' => $gsc], ['window_days' => 28, 'metrics' => $gsc],
                        ['window_days' => 90, 'metrics' => $gsc],
                    ],
                    'branded_non_branded' => ['branded' => $gsc, 'non_branded' => $gsc],
                    'detector_findings' => [],
                    'freshness' => ['lag_days_required' => 3, 'max_source_age_days' => 10, 'min_source_date' => '2026-06-01', 'max_source_date' => '2026-08-27'],
                    'mapping_state' => 'mapped', 'quality_gate_status' => 'pass', 'window_complete' => true,
                    'current_window_readable' => true, 'valid_measurement_present' => true,
                    'explicit_zero_proof' => false, 'all_relevant_values_zero' => false,
                    'verified_facts' => [], 'associations' => [], 'hypotheses' => [], 'unknowns' => [],
                ] : [
                    'windows' => [
                        ['window_days' => 7, 'metrics' => $funnel], ['window_days' => 28, 'metrics' => $funnel],
                        ['window_days' => 90, 'metrics' => $funnel],
                    ],
                    'stage_coverage' => [
                        'landing' => true, 'start' => true, 'completion' => true, 'aggregate_outcome_view' => true,
                        'return_public_content' => true, 'cta' => true,
                    ],
                    'freshness' => ['age_hours' => 1, 'max_age_hours' => 48, 'latest_refresh_status' => 'success'],
                    'revision_hash' => str_repeat('c', 64), 'mapping_state' => 'mapped',
                    'quality_gate_status' => 'pass', 'window_complete' => true, 'current_window_readable' => true,
                    'valid_measurement_present' => true, 'explicit_zero_proof' => false, 'all_relevant_values_zero' => false,
                    'verified_facts' => [], 'associations' => [], 'hypotheses' => [], 'unknowns' => [],
                ];

                $bundle = app(SeoEvidenceBundleFactory::class)->create([
                    'bundle_id' => 'bundle:11f:'.$modeId, 'bundle_version' => 2, 'mission_id' => $missionId,
                    'source_type' => $sourceType, 'source_ref' => $revision, 'authority_type' => 'measurement_readmodel',
                    'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'evidence_state' => 'verified',
                    'freshness_state' => 'fresh', 'source_capability_state' => 'available',
                    'retention_class' => 'first_party_aggregate', 'page_family' => $pageFamily, 'locale' => $locale,
                    'authority_revision' => $revision, 'source_license_class' => 'first_party',
                    'data_usage_purpose' => 'measurement_review', 'egress_decision' => 'not_required',
                    'lineage_refs' => [], 'payload' => $payload,
                ]);

                return MeasurementEvidenceLoadResult::make(
                    $modeId, [$bundle], 'available', 'fresh', MeasurementEvidenceLoadResult::NONE, $revision
                );
            }
        };
        $this->app->instance(MeasurementEvidenceBundleLoader::class, $loader);
        $this->app->instance(MeasurementEvidenceDiagnosticLoader::class, $loader);
        $builder = app(MeasurementCloseoutBuilder::class);
        $staging = $builder->build($sha, 'staging_runtime', $sha);
        $production = $builder->build($sha, 'production_runtime', $sha);

        $this->assertSame('STAGING_READY', $staging['closeout_state']);
        $this->assertSame('HOLD', $staging['SEO-PLATFORM-11F']);
        $this->assertSame('CLOSED', $production['closeout_state']);
        $this->assertSame('CLOSED', $production['SEO-PLATFORM-11F']);
        $this->assertTrue($production['ready_for_11G']);
        $this->assertSame('available', $production['evidence_source_state']);
        $this->assertSame('fresh', $production['evidence_freshness_state']);
        $this->assertSame('NONE', $production['search_hold_reason']);
        $this->assertSame('NONE', $production['cro_hold_reason']);
        $this->assertSame('valid', $production['search_bundle_verification']);
        $this->assertSame('valid', $production['cro_bundle_verification']);
        $this->assertTrue($builder->verify($production, $sha, 'production_runtime'));

        $wrongActive = $builder->build($sha, 'production_runtime', str_repeat('b', 40));
        $this->assertSame('DEPENDENCY_HOLD', $wrongActive['closeout_state']);
        $this->assertFalse($wrongActive['ready_for_11G']);
    }

    public function test_any_recorded_call_write_or_permission_holds_closeout(): void
    {
        $sha = str_repeat('a', 40);
        foreach (['model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions'] as $activity) {
            $ledger = new MeasurementActivityLedger;
            $ledger->record($activity);
            $this->app->instance(MeasurementActivityLedger::class, $ledger);
            $receipt = app(MeasurementCloseoutBuilder::class)->build($sha, 'ci_candidate', $sha);
            $this->assertSame(1, $receipt[$activity], $activity);
            $this->assertSame('DEPENDENCY_HOLD', $receipt['closeout_state'], $activity);
            $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11F'], $activity);
        }
    }

    public function test_search_and_cro_holds_are_recorded_independently_without_masking(): void
    {
        $this->bindRuntimeDependencies();
        $this->app->instance(MeasurementEvidenceDiagnosticLoader::class, new class implements MeasurementEvidenceDiagnosticLoader
        {
            public function diagnoseForRuntime(string $missionId, string $modeId, string $environment): MeasurementEvidenceLoadResult
            {
                return $modeId === 'search_measurement'
                    ? MeasurementEvidenceLoadResult::make($modeId, [], 'held', 'stale', 'GSC_STALE')
                    : MeasurementEvidenceLoadResult::make($modeId, [], 'unavailable', 'unknown', 'CRO_SCHEMA_UNAVAILABLE');
            }

            public function diagnoseForScope(string $missionId, string $modeId, string $pageFamily, string $locale, string $environment): MeasurementEvidenceLoadResult
            {
                return $this->diagnoseForRuntime($missionId, $modeId, $environment);
            }
        });

        $receipt = app(MeasurementCloseoutBuilder::class)->build(str_repeat('a', 40), 'staging_runtime', str_repeat('a', 40));

        $this->assertSame('GSC_STALE', $receipt['search_hold_reason']);
        $this->assertSame('CRO_SCHEMA_UNAVAILABLE', $receipt['cro_hold_reason']);
        $this->assertSame('stale', $receipt['search_freshness_state']);
        $this->assertSame('unknown', $receipt['cro_freshness_state']);
        $this->assertSame('DEPENDENCY_HOLD', $receipt['closeout_state']);
    }

    private function bindRuntimeDependencies(): void
    {
        $this->app->instance(TechnicalDiagnosisDependencyBindingSource::class, new class implements TechnicalDiagnosisDependencyBindingSource
        {
            public function technicalDiagnosisBinding(string $releaseSha): array
            {
                return [
                    'url_truth_revision' => 'url-truth-set-v1:'.str_repeat('1', 32),
                    'url_truth_projection_hash' => str_repeat('2', 64),
                    'runtime_evidence_revision' => 'seo-platform-07-technical-health.v1:'.str_repeat('3', 32),
                    'runtime_evidence_hash' => str_repeat('4', 64), 'authority_revision' => 'authority-set-v1:'.str_repeat('5', 32),
                    'deployment_revision' => $releaseSha, 'source_capability_state' => 'available',
                ];
            }
        });
    }
}
