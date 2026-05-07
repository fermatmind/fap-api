<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormInternalPercentileResolver;
use App\Services\BigFive\Norms\BigFiveNormRecomputeEngine;
use App\Services\BigFive\Norms\BigFiveNormSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InternalPercentileTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_percentile_resolution_is_not_public_or_frontend_exposed(): void
    {
        $target = $this->observation('obs-a', ['O' => 10.0]);
        $this->observation('obs-b', ['O' => 30.0]);
        $snapshot = (new BigFiveNormSnapshotBuilder())->build([
            'snapshot_version' => 'big5_norm_internal_percentile_feature_v1',
            'parent_snapshot_version' => 'big5_norm_snapshot_parent_v1',
        ]);
        $recompute = (new BigFiveNormRecomputeEngine())->recompute($snapshot)->toArray();

        $decision = (new BigFiveNormInternalPercentileResolver())->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => [
                'cell_count' => 2,
                'minimum_cell_count' => 2,
                'small_cell_suppressed' => false,
                'sparse_segment_rejected' => false,
            ],
        ])->toArray();

        $this->assertTrue((bool) $decision['allowed']);
        $this->assertSame(['O' => 50], $decision['percentiles']);
        $this->assertSame('disabled', data_get($decision, 'metadata.public_percentile_display'));
        $this->assertSame('disabled', data_get($decision, 'metadata.frontend_exposure'));
        $this->assertFalse((bool) data_get($decision, 'metadata.public_response_allowed'));
        $this->assertFalse((bool) data_get($decision, 'metadata.user_visible_percentile_allowed'));
        $this->assertArrayNotHasKey('user_id', $decision['metadata']);
        $this->assertArrayNotHasKey('anon_id', $decision['metadata']);
        $this->assertArrayNotHasKey('email', $decision['metadata']);
        $this->assertArrayNotHasKey('phone', $decision['metadata']);
    }

    /**
     * @param  array<string,float>  $scores
     */
    private function observation(string $id, array $scores): BigFiveNormObservation
    {
        return BigFiveNormObservation::query()->create([
            'id' => $id,
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'internal-percentile-feature-'.$id,
            'observation_source' => 'internal_percentile_feature_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_120',
            'content_version' => 'big5-v2-content-v0.1',
            'score_version' => 'score-v1',
            'score_trace_hash' => hash('sha256', $id),
            'norm_eligibility_status' => 'eligible',
            'norm_excluded' => false,
            'exclusion_reasons_json' => [],
            'quality_level' => 'A',
            'quality_flags_json' => [],
            'locale' => 'en-US',
            'region' => 'US',
            'age_band' => '18-29',
            'gender_bucket' => 'all',
            'occupation_bucket' => 'general',
            'raw_domain_scores_json' => $scores,
            'raw_facet_scores_json' => ['O1' => $scores['O'] ?? 0.0],
            'observed_at' => now(),
        ]);
    }
}
