<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\Domain\Career\Publish\FirstWavePublishReadyValidator;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Models\OccupationTruthMetric;
use App\Models\ProfileProjection;
use App\Models\SourceTrace;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirstWavePublishReadyValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_missing_manifest_subjects_as_blocked(): void
    {
        $report = app(FirstWavePublishReadyValidator::class)->validate();

        $this->assertSame(10, count($report['occupations']));
        $this->assertSame(0, $report['counts']['publish_ready']);
        $this->assertSame(0, $report['counts']['partial']);
        $this->assertSame(10, $report['counts']['blocked']);
        $this->assertContains('occupation_missing', $report['occupations'][0]['missing_requirements']);

        $software = collect($report['occupations'])->firstWhere('canonical_slug', 'software-developers');
        $marketing = collect($report['occupations'])->firstWhere('canonical_slug', 'marketing-managers');
        $this->assertIsArray($software);
        $this->assertSame('blocked_override_eligible', $software['blocked_governance_status']);
        $this->assertTrue($software['override_eligible']);
        $this->assertIsArray($marketing);
        $this->assertSame('blocked_not_safely_remediable', $marketing['blocked_governance_status']);
        $this->assertFalse($marketing['override_eligible']);
    }

    public function test_it_reports_a_manifest_subject_as_publish_ready_when_the_full_chain_is_materialized(): void
    {
        $manifest = app(FirstWaveManifestReader::class)->read();
        $subject = collect($manifest['occupations'])->firstWhere('canonical_slug', 'data-scientists');
        $this->assertIsArray($subject);

        $family = OccupationFamily::query()->create([
            'id' => $subject['family_uuid'],
            'canonical_slug' => 'computer-and-information-technology',
            'title_en' => 'Computer And Information Technology',
            'title_zh' => '',
        ]);

        $occupation = Occupation::query()->create([
            'id' => $subject['occupation_uuid'],
            'family_id' => $family->id,
            'canonical_slug' => $subject['canonical_slug'],
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => $subject['crosswalk_mode'],
            'canonical_title_en' => $subject['canonical_title_en'],
            'canonical_title_zh' => (string) ($subject['canonical_title_zh'] ?? ''),
            'search_h1_zh' => (string) ($subject['canonical_title_zh'] ?? ''),
            'structural_stability' => 0.85,
            'task_prototype_signature' => [
                'analysis' => 0.9,
                'build' => 0.7,
                'coordination' => 0.55,
            ],
            'market_semantics_gap' => 0.15,
            'regulatory_divergence' => 0.05,
            'toolchain_divergence' => 0.1,
            'skill_gap_threshold' => 0.35,
            'trust_inheritance_scope' => [
                'allow_task_truth' => true,
                'allow_pay_direct_inheritance' => false,
            ],
        ]);

        foreach ([
            ['alias' => 'Data Scientist', 'normalized' => 'data scientist', 'lang' => 'en'],
            ['alias' => 'Data Scientists', 'normalized' => 'data scientists', 'lang' => 'en'],
            ['alias' => '数据科学家', 'normalized' => '数据科学家', 'lang' => 'zh-CN'],
        ] as $alias) {
            OccupationAlias::query()->create([
                'occupation_id' => $occupation->id,
                'family_id' => $family->id,
                'alias' => $alias['alias'],
                'normalized' => $alias['normalized'],
                'lang' => $alias['lang'],
                'register' => 'alias',
                'intent_scope' => 'exact',
                'target_kind' => 'occupation',
                'precision_score' => 1.0,
                'confidence_score' => 1.0,
            ]);
        }

        $sourceTrace = SourceTrace::query()->create([
            'source_id' => 'data-scientists-source',
            'source_type' => 'fixture_dataset',
            'title' => 'Data Scientists Source',
            'url' => 'https://example.test/data-scientists',
            'fields_used' => ['median_pay_usd_annual', 'outlook_pct_2024_2034', 'ai_exposure'],
            'retrieved_at' => now()->subDay(),
            'evidence_strength' => 0.95,
        ]);

        $truthMetric = OccupationTruthMetric::query()->create([
            'occupation_id' => $occupation->id,
            'source_trace_id' => $sourceTrace->id,
            'median_pay_usd_annual' => 112590,
            'jobs_2024' => 245900,
            'projected_jobs_2034' => 328300,
            'employment_change' => 82500,
            'outlook_pct_2024_2034' => 34,
            'outlook_description' => 'Much faster than average',
            'entry_education' => "Bachelor's degree",
            'work_experience' => null,
            'on_the_job_training' => null,
            'ai_exposure' => 9,
            'ai_rationale' => 'fixture',
            'truth_market' => 'US',
            'effective_at' => now()->subDays(7),
            'reviewed_at' => now()->subDay(),
        ]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'fixture.v1',
            'dataset_checksum' => 'checksum-data-scientists',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        $trustManifest = TrustManifest::query()->create([
            'occupation_id' => $occupation->id,
            'content_version' => 'career_first_wave.publish_seed.v1',
            'data_version' => 'fixture.v1',
            'logic_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'locale_context' => ['truth_market' => 'US', 'display_market' => 'US'],
            'methodology' => ['scope_mode' => 'first_wave_exact'],
            'reviewer_status' => 'approved',
            'reviewer_id' => null,
            'reviewed_at' => now(),
            'ai_assistance' => ['ingestion' => 'fixture'],
            'quality' => ['confidence' => 0.92, 'confidence_score' => 92, 'review_required' => false],
            'last_substantive_update_at' => now(),
            'next_review_due_at' => null,
            'import_run_id' => $importRun->id,
        ]);

        $indexState = IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => 'indexable',
            'index_eligible' => true,
            'canonical_path' => '/career/jobs/data-scientists',
            'canonical_target' => null,
            'reason_codes' => ['first_wave_publish_seed'],
            'changed_at' => now(),
            'import_run_id' => $importRun->id,
        ]);

        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);

        $contextSnapshot = ContextSnapshot::query()->create([
            'compile_run_id' => $compileRun->id,
            'visitor_id' => 'career-wave:test:data-scientists',
            'captured_at' => now(),
            'current_occupation_id' => $occupation->id,
            'employment_status' => 'authority_baseline',
            'monthly_comp_band' => 'unknown',
            'burnout_level' => 0.35,
            'switch_urgency' => 0.42,
            'risk_tolerance' => 0.55,
            'geo_region' => 'US',
            'family_constraint_level' => 0.4,
            'manager_track_preference' => 0.5,
            'time_horizon_months' => 12,
            'context_payload' => [
                'materialization' => 'career_first_wave',
            ],
        ]);

        $profileProjection = ProfileProjection::query()->create([
            'compile_run_id' => $compileRun->id,
            'context_snapshot_id' => $contextSnapshot->id,
            'visitor_id' => 'career-wave:test:data-scientists',
            'projection_version' => 'career_projection.v1',
            'psychometric_axis_coverage' => 0.66,
            'projection_payload' => [
                'fit_axes' => [
                    'abstraction' => 0.72,
                    'autonomy' => 0.65,
                    'collaboration' => 0.5,
                    'variability' => 0.58,
                    'variant_trigger_load' => 0.12,
                ],
                'materialization' => 'career_first_wave',
            ],
        ]);

        app(CareerRecommendationCompiler::class)->compile($profileProjection, $occupation, [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
            'truth_metric_id' => $truthMetric->id,
            'trust_manifest_id' => $trustManifest->id,
            'index_state_id' => $indexState->id,
        ]);

        $report = app(FirstWavePublishReadyValidator::class)->validate();
        $row = collect($report['occupations'])->firstWhere('canonical_slug', 'data-scientists');

        $this->assertSame('publish_ready', $row['status']);
        $this->assertSame([], $row['missing_requirements']);
        $this->assertSame(1, $report['counts']['publish_ready']);
        $this->assertSame(9, $report['counts']['blocked']);
    }
}
