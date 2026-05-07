<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormRecomputeEngine;
use App\Services\BigFive\Norms\BigFiveNormSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class NormRecomputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recompute_engine_is_deterministic_and_internal_only(): void
    {
        $low = $this->observation('obs-a', ['O' => 10.0, 'C' => 20.0]);
        $mid = $this->observation('obs-b', ['O' => 20.0, 'C' => 30.0]);
        $high = $this->observation('obs-c', ['O' => 30.0, 'C' => 40.0]);

        $snapshot = (new BigFiveNormSnapshotBuilder())->build(['snapshot_version' => 'big5_norm_snapshot_recompute_v1']);
        $engine = new BigFiveNormRecomputeEngine();

        $first = $engine->recompute($snapshot)->toArray();
        $second = $engine->recompute($snapshot)->toArray();

        $this->assertSame($first, $second);
        $this->assertSame('big5_norm_recompute', data_get($first, 'summary.mode'));
        $this->assertSame('disabled', data_get($first, 'summary.public_percentile_display'));
        $this->assertSame('disabled', data_get($first, 'summary.runtime_attachment'));
        $this->assertSame('disabled', data_get($first, 'summary.frontend_exposure'));
        $this->assertSame('blocked', data_get($first, 'summary.fake_percentile_fallback'));
        $this->assertSame(['C' => ['mean' => 30.0, 'sd' => 10.0, 'sample_n' => 3], 'O' => ['mean' => 20.0, 'sd' => 10.0, 'sample_n' => 3]], $first['metrics']);
        $this->assertSame(['C' => 33, 'O' => 33], $first['internal_percentiles'][$low->id] ?? null);
        $this->assertSame(['C' => 67, 'O' => 67], $first['internal_percentiles'][$mid->id] ?? null);
        $this->assertSame(['C' => 100, 'O' => 100], $first['internal_percentiles'][$high->id] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($first, 'summary.output_hash'));
    }

    public function test_recompute_fails_closed_for_mutated_snapshot_without_hash(): void
    {
        $this->observation('obs-a', ['O' => 10.0]);
        $snapshotArray = (new BigFiveNormSnapshotBuilder())->build(['snapshot_version' => 'big5_norm_snapshot_recompute_v2'])->toArray();
        unset($snapshotArray['snapshot_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('snapshot hash is required');

        (new BigFiveNormRecomputeEngine())->recompute(new \App\Services\BigFive\Norms\BigFiveNormSnapshot($snapshotArray));
    }

    public function test_recompute_rejects_public_or_runtime_attached_snapshots(): void
    {
        $this->observation('obs-a', ['O' => 10.0]);
        $snapshotArray = (new BigFiveNormSnapshotBuilder())->build(['snapshot_version' => 'big5_norm_snapshot_recompute_v3'])->toArray();
        data_set($snapshotArray, 'release_metadata.public_percentile_display', 'enabled');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('public percentile display must remain disabled');

        (new BigFiveNormRecomputeEngine())->recompute(new \App\Services\BigFive\Norms\BigFiveNormSnapshot($snapshotArray));
    }

    /**
     * @param  array<string,float>  $scores
     */
    private function observation(string $id, array $scores): BigFiveNormObservation
    {
        return BigFiveNormObservation::query()->create([
            'id' => $id,
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'norm-recompute-'.$id,
            'observation_source' => 'recompute_test',
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
