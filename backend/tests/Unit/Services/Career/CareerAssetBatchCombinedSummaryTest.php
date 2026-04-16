<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerAssetBatchCombinedSummaryTest extends TestCase
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

    public function test_it_builds_combined_summary_for_multi_manifest_execution(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $batch2 = $this->createManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_2, 30, 'exact', 'candidate');
        $batch3 = $this->createManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_3, 80, 'functional_equivalent', 'candidate');
        $batch4 = $this->createManifest(CareerAssetBatchManifestBuilder::BATCH_KIND_4, 222, 'unmapped', 'hold');

        $exitCode = Artisan::call('career:run-asset-batch', [
            '--manifest' => [$batch2, $batch3, $batch4],
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
        $this->assertGreaterThan(0, (int) data_get($payload, 'combined_summary.unmapped'));
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

    private function createManifest(string $batchKind, int $memberCount, string $mode, string $track): string
    {
        $members = [];

        for ($i = 1; $i <= $memberCount; $i++) {
            $slug = sprintf('%s-combined-%03d', str_replace('career_asset_', 'b', $batchKind), $i);
            $members[] = [
                'occupation_uuid' => sprintf('40000000-0000-0000-0000-%012d', $i),
                'canonical_slug' => $slug,
                'canonical_title_en' => Str::of($slug)->replace('-', ' ')->title()->toString(),
                'family_slug' => 'batch-family-'.$batchKind,
                'crosswalk_mode' => $mode,
                'batch_role' => $track === 'stable' ? 'stable_seed' : ($track === 'candidate' ? 'candidate_seed' : 'hold_seed'),
                'stable_seed' => $track === 'stable',
                'candidate_seed' => $track === 'candidate',
                'hold_seed' => $track !== 'stable' && $track !== 'candidate',
                'expected_publish_track' => $track,
            ];
        }

        $payload = [
            'batch_kind' => $batchKind,
            'batch_version' => 'career.asset_batch.manifest.v2',
            'batch_key' => $batchKind.'-combined-test',
            'scope' => match ($batchKind) {
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
}
