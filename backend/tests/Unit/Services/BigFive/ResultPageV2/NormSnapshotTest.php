<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NormSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const GOVERNANCE_PATH = 'content_assets/big5/result_page_v2/governance/norm_snapshot_policy_v0_1';

    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/norm_snapshot_readiness/v0_1';

    public function test_snapshot_is_immutable_reproducible_and_traceable(): void
    {
        $this->observation('a', 'A', false, ['O' => 10.0, 'C' => 20.0]);
        $this->observation('b', 'B', false, ['O' => 14.0, 'C' => 24.0]);
        $this->observation('c', 'C', false, ['O' => 99.0, 'C' => 99.0]);
        $this->observation('d', 'A', true, ['O' => 88.0, 'C' => 88.0]);

        $builder = new BigFiveNormSnapshotBuilder;
        $first = $builder->build(['snapshot_version' => 'big5_norm_snapshot_2026_05_07_v1'])->toArray();
        $second = $builder->build(['snapshot_version' => 'big5_norm_snapshot_2026_05_07_v1'])->toArray();

        $this->assertSame($first, $second);
        $this->assertSame('big5_norm_snapshot.v0.1', $first['schema_version'] ?? null);
        $this->assertFalse((bool) data_get($first, 'immutability.mutable', true));
        $this->assertFalse((bool) data_get($first, 'immutability.overwrite_allowed', true));
        $this->assertSame('new_snapshot_version_required', data_get($first, 'immutability.rebuild_policy'));
        $this->assertSame(2, data_get($first, 'observation_cut.included_observation_count'));
        $this->assertSame(2, data_get($first, 'observation_cut.excluded_observation_count'));
        $this->assertCount(2, data_get($first, 'observation_cut.entries'));
        $this->assertNotEmpty($first['input_manifest_hash'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($first['snapshot_hash'] ?? ''));
    }

    public function test_snapshot_lineage_and_rollback_linkage_are_explicit_without_runtime_attachment(): void
    {
        $this->observation('a', 'A', false, ['O' => 10.0]);

        $snapshot = (new BigFiveNormSnapshotBuilder)->build([
            'snapshot_version' => 'big5_norm_snapshot_2026_05_07_v2',
            'parent_snapshot_version' => 'big5_norm_snapshot_2026_05_01_v1',
            'rollback_target_snapshot_version' => 'big5_norm_snapshot_2026_05_01_v1',
        ])->toArray();

        $this->assertSame('big5_norm_snapshot_2026_05_01_v1', data_get($snapshot, 'lineage.parent_snapshot_version'));
        $this->assertSame('big5_norm_snapshot_2026_05_01_v1', data_get($snapshot, 'lineage.rollback_target_snapshot_version'));
        $this->assertSame('snapshot_revert', data_get($snapshot, 'lineage.rollback_linkage'));
        $this->assertSame('disabled', data_get($snapshot, 'release_metadata.public_percentile_display'));
        $this->assertSame('disabled', data_get($snapshot, 'release_metadata.runtime_attachment'));
        $this->assertSame('disabled', data_get($snapshot, 'release_metadata.production_rollout'));
    }

    public function test_governance_and_qa_packages_document_snapshot_controls(): void
    {
        $policy = $this->jsonFile(self::GOVERNANCE_PATH, 'big5_v2_norm_snapshot_policy_v0_1.json');
        $report = $this->jsonFile(self::QA_PATH, 'big5_v2_norm_snapshot_readiness_v0_1.json');

        $this->assertSame('immutable_norm_snapshot', $policy['snapshot_control'] ?? null);
        $this->assertTrue((bool) ($policy['new_version_required_for_rebuild'] ?? false));
        $this->assertSame('snapshot_revert', $policy['rollback_linkage'] ?? null);
        $this->assertSame('GO_INTERNAL_ONLY', $report['snapshot_readiness'] ?? null);
        $this->assertFalse((bool) ($policy['public_percentile_display_enabled'] ?? true));
        $this->assertFalse((bool) ($report['public_percentile_display_enabled'] ?? true));
        $this->assertFalse((bool) ($policy['runtime_percentile_attachment_enabled'] ?? true));
        $this->assertFalse((bool) ($report['runtime_percentile_attachment_enabled'] ?? true));
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $this->assertSha256Sums(self::GOVERNANCE_PATH);
        $this->assertSha256Sums(self::QA_PATH);
    }

    /**
     * @param  array<string,float>  $scores
     */
    private function observation(string $key, string $qualityLevel, bool $excluded, array $scores): BigFiveNormObservation
    {
        return BigFiveNormObservation::query()->create([
            'id' => (string) Str::uuid(),
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'norm-snapshot-'.$key,
            'observation_source' => 'snapshot_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_120',
            'content_version' => 'big5-v2-content-v0.1',
            'score_version' => 'score-v1',
            'score_trace_hash' => hash('sha256', $key),
            'norm_eligibility_status' => $excluded ? 'excluded' : 'eligible',
            'norm_excluded' => $excluded,
            'exclusion_reasons_json' => $excluded ? ['test_excluded'] : [],
            'quality_level' => $qualityLevel,
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

    private function assertSha256Sums(string $path): void
    {
        $entries = file(base_path($path.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $this->assertSame($expectedHash, hash_file('sha256', base_path($path.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path, string $fileName): array
    {
        $json = file_get_contents(base_path($path.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
