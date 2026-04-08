<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\IndexStateValue;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationCompilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_complete_high_trust_compiled_bundle_with_provenance_refs(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();

        $snapshot = app(CareerRecommendationCompiler::class)->compile(
            $chain['childProjection'],
            $chain['occupation'],
        );

        $payload = $snapshot->snapshot_payload;

        $this->assertSame(CareerRecommendationCompiler::COMPILER_VERSION, $snapshot->compiler_version);
        $this->assertSame($chain['trustManifest']->id, $snapshot->trust_manifest_id);
        $this->assertSame($chain['indexState']->id, $snapshot->index_state_id);
        $this->assertSame($chain['truthMetric']->id, $snapshot->truth_metric_id);
        $this->assertNotNull($snapshot->compiled_at);
        $this->assertSame(CareerRecommendationCompiler::COMPILER_VERSION, data_get($payload, 'compile_refs.compiler_version'));
        $this->assertSame($chain['skillGraph']->id, data_get($payload, 'compile_refs.skill_graph_id'));
        $this->assertSame([$chain['crosswalk']->id], data_get($payload, 'compile_refs.crosswalk_ids'));
        $this->assertIsString(data_get($payload, 'compile_refs.occupation_state_hash'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertArrayHasKey('warnings', $payload);
        $this->assertArrayHasKey('claim_permissions', $payload);
        $this->assertArrayHasKey('integrity_summary', $payload);
    }

    public function test_it_compiles_trust_limited_cross_market_claim_permissions_conservatively(): void
    {
        $chain = CareerFoundationFixture::seedTrustLimitedCrossMarketChain();

        $snapshot = app(CareerRecommendationCompiler::class)->compile(
            $chain['childProjection'],
            $chain['occupation'],
        );

        $payload = $snapshot->snapshot_payload;

        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_salary_comparison'));
        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_cross_market_pay_copy'));
        $this->assertContains('cross_market_mismatch', (array) data_get($payload, 'warnings.amber_flags'));
        $this->assertContains('salary_comparison', (array) data_get($payload, 'warnings.blocked_claims'));
    }

    public function test_it_marks_missing_truth_fields_explicitly_in_scores_and_claims(): void
    {
        $chain = CareerFoundationFixture::seedMissingTruthChain();

        $snapshot = app(CareerRecommendationCompiler::class)->compile(
            $chain['childProjection'],
            $chain['occupation'],
        );

        $payload = $snapshot->snapshot_payload;

        $this->assertContains('ai_exposure', (array) data_get($payload, 'score_bundle.ai_survival_score.critical_missing_fields'));
        $this->assertSame('blocked', data_get($payload, 'score_bundle.ai_survival_score.integrity_state'));
        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_ai_strategy'));
        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_salary_comparison'));
        $this->assertContains('missing_ai_exposure', (array) data_get($payload, 'warnings.red_flags'));
    }

    public function test_it_blocks_strong_claims_when_index_state_is_noindex_even_with_otherwise_healthy_inputs(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-noindex',
            'index_state' => IndexStateValue::NOINDEX,
            'index_eligible' => false,
        ]);

        $snapshot = app(CareerRecommendationCompiler::class)->compile(
            $chain['childProjection'],
            $chain['occupation'],
        );

        $payload = $snapshot->snapshot_payload;

        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_strong_claim'));
        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_salary_comparison'));
        $this->assertContains('index_state_restricted', (array) data_get($payload, 'warnings.red_flags'));
        $this->assertContains('strong_claim', (array) data_get($payload, 'warnings.blocked_claims'));
    }

    public function test_it_keeps_compile_persistence_append_friendly(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $baselineCount = $chain['occupation']->recommendationSnapshots()->count();

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $this->assertSame($baselineCount + 2, $chain['occupation']->recommendationSnapshots()->count());
        $this->assertDatabaseHas('recommendation_snapshots', ['id' => $chain['recommendationSnapshot']->id]);
    }

    public function test_provenance_patch_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('recommendation_snapshots', 'compiler_version'));
        $this->assertTrue(Schema::hasColumn('recommendation_snapshots', 'trust_manifest_id'));
        $this->assertTrue(Schema::hasColumn('recommendation_snapshots', 'index_state_id'));
        $this->assertTrue(Schema::hasColumn('recommendation_snapshots', 'truth_metric_id'));
        $this->assertTrue(Schema::hasColumn('recommendation_snapshots', 'compiled_at'));
    }
}
