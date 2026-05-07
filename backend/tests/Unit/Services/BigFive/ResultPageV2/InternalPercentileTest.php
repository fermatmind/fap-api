<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormInternalPercentileResolver;
use App\Services\BigFive\Norms\BigFiveNormRecomputeEngine;
use App\Services\BigFive\Norms\BigFiveNormSnapshot;
use App\Services\BigFive\Norms\BigFiveNormSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InternalPercentileTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_percentile_resolves_only_with_snapshot_and_drift_safe_context(): void
    {
        $this->observation('obs-a', ['O' => 10.0]);
        $target = $this->observation('obs-b', ['O' => 20.0]);
        $this->observation('obs-c', ['O' => 30.0]);

        $snapshot = $this->snapshot();
        $recompute = (new BigFiveNormRecomputeEngine())->recompute($snapshot)->toArray();
        $decision = (new BigFiveNormInternalPercentileResolver())->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => [
                'cell_count' => 3,
                'minimum_cell_count' => 2,
                'small_cell_suppressed' => false,
                'sparse_segment_rejected' => false,
            ],
        ])->toArray();

        $this->assertTrue((bool) $decision['allowed']);
        $this->assertSame('resolved_internal_only', $decision['status']);
        $this->assertSame(['O' => 67], $decision['percentiles']);
        $this->assertSame('disabled', data_get($decision, 'metadata.public_percentile_display'));
        $this->assertSame('disabled', data_get($decision, 'metadata.frontend_exposure'));
        $this->assertFalse((bool) data_get($decision, 'metadata.user_visible_percentile_allowed'));
        $this->assertArrayNotHasKey('user_id', $decision['metadata']);
        $this->assertArrayNotHasKey('anon_id', $decision['metadata']);
    }

    public function test_internal_percentile_fails_closed_for_stale_snapshot_sparse_segment_and_drift_alert(): void
    {
        $target = $this->observation('obs-a', ['O' => 10.0]);
        $snapshot = $this->snapshot();
        $recompute = (new BigFiveNormRecomputeEngine())->recompute($snapshot)->toArray();
        $resolver = new BigFiveNormInternalPercentileResolver();

        $this->assertSame('stale_snapshot', $resolver->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => ['cell_count' => 10, 'minimum_cell_count' => 2],
        ], ['snapshot_stale' => true])->reason);

        $this->assertSame('sparse_segment', $resolver->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => ['cell_count' => 1, 'minimum_cell_count' => 2],
        ])->reason);

        $this->assertSame('drift_alert_active', $resolver->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => ['cell_count' => 10, 'minimum_cell_count' => 2],
        ], ['drift_status' => 'alert'])->reason);
    }

    public function test_internal_percentile_fails_closed_for_missing_lineage_or_mismatched_recompute(): void
    {
        $target = $this->observation('obs-a', ['O' => 10.0]);
        $snapshot = $this->snapshot();
        $recompute = (new BigFiveNormRecomputeEngine())->recompute($snapshot)->toArray();
        $snapshotArray = $snapshot->toArray();
        unset($snapshotArray['lineage']);

        $resolver = new BigFiveNormInternalPercentileResolver();
        $this->assertSame('missing_snapshot_lineage', $resolver->resolve(new BigFiveNormSnapshot($snapshotArray), $recompute, [
            'observation_id' => $target->id,
            'segment' => ['cell_count' => 10, 'minimum_cell_count' => 2],
        ])->reason);

        data_set($recompute, 'summary.snapshot_hash', 'mismatched');
        $this->assertSame('snapshot_hash_mismatch', $resolver->resolve($snapshot, $recompute, [
            'observation_id' => $target->id,
            'segment' => ['cell_count' => 10, 'minimum_cell_count' => 2],
        ])->reason);
    }

    private function snapshot(): BigFiveNormSnapshot
    {
        return (new BigFiveNormSnapshotBuilder())->build([
            'snapshot_version' => 'big5_norm_internal_percentile_v1',
            'parent_snapshot_version' => 'big5_norm_snapshot_parent_v1',
            'rollback_target_snapshot_version' => 'big5_norm_snapshot_parent_v1',
        ]);
    }

    /**
     * @param  array<string,float>  $scores
     */
    private function observation(string $id, array $scores): BigFiveNormObservation
    {
        return BigFiveNormObservation::query()->create([
            'id' => $id,
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'internal-percentile-'.$id,
            'observation_source' => 'internal_percentile_test',
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
