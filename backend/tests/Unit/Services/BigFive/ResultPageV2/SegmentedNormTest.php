<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormSegmentedAggregator;
use App\Services\BigFive\Norms\BigFiveNormSnapshot;
use App\Services\BigFive\Norms\BigFiveNormSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class SegmentedNormTest extends TestCase
{
    use RefreshDatabase;

    public function test_segmented_aggregation_supports_locale_region_age_gender_and_occupation(): void
    {
        $this->observation('obs-a', ['O' => 10.0, 'C' => 20.0], [
            'locale' => 'en-US',
            'region' => 'US',
            'age_band' => '18-29',
            'gender_bucket' => 'all',
            'occupation_bucket' => 'student',
        ]);
        $this->observation('obs-b', ['O' => 30.0, 'C' => 40.0], [
            'locale' => 'en-US',
            'region' => 'US',
            'age_band' => '18-29',
            'gender_bucket' => 'all',
            'occupation_bucket' => 'student',
        ]);
        $this->observation('obs-c', ['O' => 80.0, 'C' => 60.0], [
            'locale' => 'zh-CN',
            'region' => 'CN',
            'age_band' => '30-39',
            'gender_bucket' => 'all',
            'occupation_bucket' => 'engineering',
        ]);

        $snapshot = (new BigFiveNormSnapshotBuilder)->build(['snapshot_version' => 'big5_norm_segmented_v1']);
        $result = (new BigFiveNormSegmentedAggregator)->aggregate($snapshot, ['minimum_cell_count' => 2])->toArray();

        $this->assertSame('big5_segmented_norm_aggregation', data_get($result, 'summary.mode'));
        $this->assertSame(['locale', 'region', 'age_band', 'gender_bucket', 'occupation_bucket'], data_get($result, 'summary.segment_fields'));
        $this->assertSame('disabled', data_get($result, 'summary.public_percentile_display'));
        $this->assertSame('disabled', data_get($result, 'summary.runtime_attachment'));
        $this->assertFalse((bool) data_get($result, 'summary.public_output_allowed'));
        $this->assertSame(3, data_get($result, 'summary.observation_count'));
        $this->assertSame(2, data_get($result, 'summary.segment_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($result, 'summary.output_hash'));

        $studentSegment = $this->segmentByKey($result['segments'], 'en-US|US|18-29|all|student');
        $this->assertSame([
            'locale' => 'en-US',
            'region' => 'US',
            'age_band' => '18-29',
            'gender_bucket' => 'all',
            'occupation_bucket' => 'student',
        ], $studentSegment['segments']);
        $this->assertFalse((bool) $studentSegment['small_cell_suppressed']);
        $this->assertFalse((bool) $studentSegment['sparse_segment_rejected']);
        $this->assertFalse((bool) $studentSegment['sparse_domain_metrics_rejected']);
        $this->assertFalse((bool) $studentSegment['public_output_allowed']);
        $this->assertSame(['C' => ['mean' => 30.0, 'sd' => 14.142136, 'sample_n' => 2], 'O' => ['mean' => 20.0, 'sd' => 14.142136, 'sample_n' => 2]], $studentSegment['domain_metrics']);
    }

    public function test_sparse_domain_metrics_are_suppressed_even_when_segment_cell_count_passes(): void
    {
        $this->observation('obs-a', ['O' => 10.0, 'C' => 20.0], ['occupation_bucket' => 'student']);
        $this->observation('obs-b', ['O' => 30.0], ['occupation_bucket' => 'student']);

        $snapshot = (new BigFiveNormSnapshotBuilder)->build(['snapshot_version' => 'big5_norm_segmented_sparse_domain_v1']);
        $result = (new BigFiveNormSegmentedAggregator)->aggregate($snapshot, ['minimum_cell_count' => 2])->toArray();

        $segment = $this->segmentByKey($result['segments'], 'en-US|US|18-29|all|student');
        $this->assertSame(2, $segment['cell_count']);
        $this->assertTrue((bool) $segment['small_cell_suppressed']);
        $this->assertTrue((bool) $segment['sparse_segment_rejected']);
        $this->assertTrue((bool) $segment['sparse_domain_metrics_rejected']);
        $this->assertNull($segment['domain_metrics']);
        $this->assertFalse((bool) $segment['public_output_allowed']);
    }

    public function test_sparse_segments_are_rejected_and_small_cell_suppressed(): void
    {
        $this->observation('obs-a', ['O' => 10.0], ['occupation_bucket' => 'student']);
        $this->observation('obs-b', ['O' => 20.0], ['occupation_bucket' => 'engineering']);

        $snapshot = (new BigFiveNormSnapshotBuilder)->build(['snapshot_version' => 'big5_norm_segmented_v2']);
        $result = (new BigFiveNormSegmentedAggregator)->aggregate($snapshot, ['minimum_cell_count' => 2])->toArray();

        foreach ($result['segments'] as $segment) {
            $this->assertTrue((bool) $segment['small_cell_suppressed']);
            $this->assertTrue((bool) $segment['sparse_segment_rejected']);
            $this->assertNull($segment['domain_metrics']);
            $this->assertFalse((bool) $segment['public_output_allowed']);
        }
    }

    public function test_segmented_aggregation_rejects_public_or_runtime_attached_snapshots(): void
    {
        $this->observation('obs-a', ['O' => 10.0]);
        $snapshotArray = (new BigFiveNormSnapshotBuilder)->build(['snapshot_version' => 'big5_norm_segmented_v3'])->toArray();
        data_set($snapshotArray, 'release_metadata.runtime_attachment', 'enabled');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('runtime attachment must remain disabled');

        (new BigFiveNormSegmentedAggregator)->aggregate(new BigFiveNormSnapshot($snapshotArray), ['minimum_cell_count' => 1]);
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return array<string,mixed>
     */
    private function segmentByKey(array $segments, string $key): array
    {
        foreach ($segments as $segment) {
            if (($segment['segment_key'] ?? null) === $key) {
                return $segment;
            }
        }

        $this->fail('Missing segment '.$key);
    }

    /**
     * @param  array<string,float>  $scores
     * @param  array<string,string>  $segments
     */
    private function observation(string $id, array $scores, array $segments = []): BigFiveNormObservation
    {
        return BigFiveNormObservation::query()->create([
            'id' => $id,
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'norm-segmented-'.$id,
            'observation_source' => 'segmented_norm_test',
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
            'locale' => $segments['locale'] ?? 'en-US',
            'region' => $segments['region'] ?? 'US',
            'age_band' => $segments['age_band'] ?? '18-29',
            'gender_bucket' => $segments['gender_bucket'] ?? 'all',
            'occupation_bucket' => $segments['occupation_bucket'] ?? 'general',
            'raw_domain_scores_json' => $scores,
            'raw_facet_scores_json' => ['O1' => $scores['O'] ?? 0.0],
            'observed_at' => now(),
        ]);
    }
}
