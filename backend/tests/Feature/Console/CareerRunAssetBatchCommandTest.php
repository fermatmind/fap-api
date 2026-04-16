<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerRunAssetBatchCommandTest extends TestCase
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

    public function test_command_runs_full_batch_pipeline_for_batch_1_manifest(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $manifestPath = $this->createBatchManifest(
            CareerAssetBatchManifestBuilder::BATCH_KIND_1,
            10,
            0,
        );

        $this->artisan('career:run-asset-batch', [
            '--manifest' => $manifestPath,
            '--mode' => 'full',
        ])
            ->expectsOutputToContain('status=completed')
            ->expectsOutputToContain('mode=full')
            ->expectsOutputToContain('stage[validate]=passed')
            ->expectsOutputToContain('stage[compile_trust]=passed')
            ->expectsOutputToContain('stage[publish_candidate]=passed')
            ->expectsOutputToContain('stage[regression]=passed')
            ->assertExitCode(0);
    }

    public function test_command_supports_batch_2_validate_mode_with_json_output_and_warnings(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $manifestPath = $this->createBatchManifest(
            CareerAssetBatchManifestBuilder::BATCH_KIND_2,
            10,
            20,
        );

        $exitCode = Artisan::call('career:run-asset-batch', [
            '--manifest' => $manifestPath,
            '--mode' => 'validate',
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame('career_asset_batch_pipeline', $payload['pipeline_kind'] ?? null);
        $this->assertSame('completed', $payload['status'] ?? null);
        $this->assertSame('validate', $payload['mode'] ?? null);
        $this->assertSame(30, data_get($payload, 'manifest.member_count'));
        $this->assertSame(0, data_get($payload, 'stages.validate.counts.invalid'));
        $this->assertSame(20, data_get($payload, 'stages.validate.counts.warnings'));
    }

    public function test_command_supports_manifest_set_and_outputs_combined_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $batch2 = $this->createBatchManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_2, 0, 30);
        $batch3 = $this->createBatchManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_3, 0, 80);
        $batch4 = $this->createBatchManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_4, 0, 222);
        $setPath = $this->createBatchSet([$batch2, $batch3, $batch4]);

        $exitCode = Artisan::call('career:run-asset-batch', [
            '--set' => $setPath,
            '--mode' => 'full',
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('career_asset_batch_execution_b2b3b4', $payload['batch_execution_kind'] ?? null);
        $this->assertSame('completed', $payload['status'] ?? null);
        $this->assertCount(3, (array) ($payload['runs'] ?? []));
        $this->assertSame(332, (int) data_get($payload, 'combined_summary.total_members'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'combined_summary.review_queue_handoff'));
        $this->assertSame(342, (int) data_get($payload, 'combined_summary.coverage_summary.expected_total_occupations'));
        $this->assertSame(342, (int) data_get($payload, 'combined_summary.coverage_summary.tracked_total_occupations'));
        $this->assertSame(0, (int) data_get($payload, 'combined_summary.coverage_summary.missing_occupations'));
        $this->assertTrue((bool) data_get($payload, 'combined_summary.coverage_summary.tracking_complete'));
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
            $crosswalkMode = match ($batchKind) {
                CareerAssetBatchManifestBuilder::BATCH_KIND_3 => 'functional_equivalent',
                CareerAssetBatchManifestBuilder::BATCH_KIND_4 => $i % 3 === 0 ? 'unmapped' : 'family_proxy',
                default => 'exact',
            };
            $expectedTrack = 'hold';
            $batchPrefix = str_replace('career_asset_', 'b', $batchKind);
            $members[] = [
                'occupation_uuid' => sprintf('10000000-0000-0000-0000-%012d', $i),
                'canonical_slug' => sprintf('%s-command-%03d', $batchPrefix, $i),
                'canonical_title_en' => sprintf('%s Command %03d', str_replace('-', ' ', ucfirst($batchPrefix)), $i),
                'family_slug' => sprintf('%s-family', $batchPrefix),
                'crosswalk_mode' => $crosswalkMode,
                'batch_role' => 'hold_seed',
                'stable_seed' => false,
                'candidate_seed' => false,
                'hold_seed' => true,
                'expected_publish_track' => $expectedTrack,
            ];
        }

        $payload = [
            'batch_kind' => $batchKind,
            'batch_version' => 'career.asset_batch.manifest.v2',
            'batch_key' => $batchKind.'-command-test',
            'scope' => match ($batchKind) {
                CareerAssetBatchManifestBuilder::BATCH_KIND_1 => 'career_batch_1_first_wave_10',
                CareerAssetBatchManifestBuilder::BATCH_KIND_2 => 'career_batch_2_30',
                CareerAssetBatchManifestBuilder::BATCH_KIND_3 => 'career_batch_3_80',
                CareerAssetBatchManifestBuilder::BATCH_KIND_4 => 'career_batch_4_222',
                default => 'career_batch_misc',
            },
            'member_count' => count($members),
            'members' => $members,
        ];

        $path = storage_path('app/private/testing/'.$payload['batch_key'].'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->manifestPaths[] = $path;

        return $path;
    }

    /**
     * @param  list<string>  $manifestPaths
     */
    private function createBatchSet(array $manifestPaths): string
    {
        $payload = [
            'set_key' => 'b71x-test-set',
            'set_version' => 'career.asset_batch.execution_set.v1',
            'manifests' => $manifestPaths,
            'coverage_baseline' => [
                'source' => 'unit-test-fixture',
                'expected_total_occupations' => 342,
                'excluded_first_wave_slugs' => [
                    'fw-placeholder-01',
                    'fw-placeholder-02',
                    'fw-placeholder-03',
                    'fw-placeholder-04',
                    'fw-placeholder-05',
                    'fw-placeholder-06',
                    'fw-placeholder-07',
                    'fw-placeholder-08',
                    'fw-placeholder-09',
                    'fw-placeholder-10',
                ],
            ],
        ];

        $path = storage_path('app/private/testing/b71x-command-test-set.json');
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
