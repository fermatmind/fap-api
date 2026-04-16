<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\Domain\Career\Production\CareerAssetBatchPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerAssetBatchPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $manifestPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->manifestPaths as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_it_runs_batch_1_full_pipeline_with_validate_compile_publish_and_regression(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $manifestPath = $this->createBatchManifest(
            CareerAssetBatchManifestBuilder::BATCH_KIND_1,
            10,
            0,
        );

        $result = app(CareerAssetBatchPipeline::class)->run($manifestPath, CareerAssetBatchPipeline::MODE_FULL);

        $this->assertSame('career_asset_batch_pipeline', $result['pipeline_kind'] ?? null);
        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame(CareerAssetBatchPipeline::MODE_FULL, $result['mode'] ?? null);
        $this->assertSame(10, data_get($result, 'manifest.member_count'));
        $this->assertTrue((bool) data_get($result, 'stages.validate.passed'));
        $this->assertTrue((bool) data_get($result, 'stages.compile_trust.passed'));
        $this->assertTrue((bool) data_get($result, 'stages.publish_candidate.passed'));
        $this->assertTrue((bool) data_get($result, 'stages.regression.passed'));
    }

    public function test_it_supports_batch_2_manifest_shape_for_30_members_and_fails_fast_on_missing_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $manifestPath = $this->createBatchManifest(
            CareerAssetBatchManifestBuilder::BATCH_KIND_2,
            10,
            20,
        );

        $result = app(CareerAssetBatchPipeline::class)->run($manifestPath, CareerAssetBatchPipeline::MODE_VALIDATE);

        $this->assertSame('aborted', $result['status'] ?? null);
        $this->assertSame(CareerAssetBatchPipeline::MODE_VALIDATE, $result['mode'] ?? null);
        $this->assertSame(30, data_get($result, 'manifest.member_count'));
        $this->assertSame(30, data_get($result, 'stages.validate.counts.total'));
        $this->assertSame(20, data_get($result, 'stages.validate.counts.invalid'));
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    private function createBatchManifest(string $batchKind, int $truthMembers, int $syntheticMembers): string
    {
        $firstWave = json_decode((string) file_get_contents(base_path('docs/career/first_wave_manifest.json')), true);
        $occupations = array_slice((array) ($firstWave['occupations'] ?? []), 0, $truthMembers);

        $members = [];
        foreach ($occupations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $track = $this->normalizeTrack((string) ($row['wave_classification'] ?? 'hold'));
            $members[] = [
                'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
                'canonical_slug' => (string) ($row['canonical_slug'] ?? ''),
                'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
                'family_slug' => 'family-'.(string) ($row['canonical_slug'] ?? ''),
                'crosswalk_mode' => (string) ($row['crosswalk_mode'] ?? 'exact'),
                'batch_role' => $track.'_seed',
                'stable_seed' => $track === 'stable',
                'candidate_seed' => $track === 'candidate',
                'hold_seed' => $track === 'hold',
                'expected_publish_track' => $track,
            ];
        }

        for ($i = 1; $i <= $syntheticMembers; $i++) {
            $members[] = [
                'occupation_uuid' => sprintf('00000000-0000-0000-0000-%012d', $i),
                'canonical_slug' => sprintf('batch-two-synthetic-%02d', $i),
                'canonical_title_en' => sprintf('Batch Two Synthetic %02d', $i),
                'family_slug' => 'batch-two-family',
                'crosswalk_mode' => 'exact',
                'batch_role' => 'hold_seed',
                'stable_seed' => false,
                'candidate_seed' => false,
                'hold_seed' => true,
                'expected_publish_track' => 'hold',
            ];
        }

        $payload = [
            'batch_kind' => $batchKind,
            'batch_version' => 'career.asset_batch.manifest.v1',
            'batch_key' => $batchKind.'-test',
            'scope' => $batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_1
                ? 'career_batch_1_first_wave_10'
                : 'career_batch_2_first_wave_30_framework',
            'member_count' => count($members),
            'members' => $members,
        ];

        $path = storage_path('app/private/testing/'.$payload['batch_key'].'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->manifestPaths[] = $path;

        return $path;
    }

    private function normalizeTrack(string $waveClassification): string
    {
        $normalized = strtolower(trim($waveClassification));

        return match ($normalized) {
            'stable' => 'stable',
            'candidate' => 'candidate',
            default => 'hold',
        };
    }
}
