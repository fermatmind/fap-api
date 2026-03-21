<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\StorageCostAnalyzerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageCostAnalyzerServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-cost-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_service_builds_stable_category_and_directory_analysis_without_side_effects(): void
    {
        $this->seedStorageTreeForAnalysis();

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        /** @var StorageCostAnalyzerService $service */
        $service = app(StorageCostAnalyzerService::class);
        $payload = $service->analyze($this->isolatedStoragePath);

        $this->assertSame('storage_cost_analyzer.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame($this->isolatedStoragePath, $payload['root_path']);
        $this->assertSame(360, data_get($payload, 'summary.total_bytes'));
        $this->assertSame(11, data_get($payload, 'summary.total_files'));
        $this->assertSame(24, data_get($payload, 'summary.total_directories'));
        $this->assertSame('runtime_or_data_truth', data_get($payload, 'summary.largest_category'));
        $this->assertSame(120, data_get($payload, 'summary.largest_category_bytes'));

        $this->assertSame([
            'path' => 'app/private/reports',
            'bytes' => 90,
            'file_count' => 1,
            'directory_count' => 0,
            'category' => 'runtime_or_data_truth',
            'risk_level' => 'no_touch',
        ], $payload['top_directories'][0]);
        $topPaths = array_values(array_map(
            static fn (array $row): string => (string) ($row['path'] ?? ''),
            $payload['top_directories']
        ));
        $this->assertSame(
            [
                'app/private/reports',
                'app/private/quarantine/release_roots',
                'app/private/packs_v2_materialized',
                'app/private/blobs',
                'app/private/control_plane_snapshots',
                'app/private/offload/blobs',
                'app/private/prune_plans',
                'app/private/custom_unknown',
                'app/private/quarantine/restore_runs',
                'framework/cache',
                'logs',
            ],
            $topPaths
        );

        $this->assertSame([
            'bytes' => 30,
            'file_count' => 1,
            'directory_count' => 1,
        ], data_get($payload, 'by_category.control_plane_snapshots'));
        $this->assertSame([
            'bytes' => 20,
            'file_count' => 1,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.control_plane_plans'));
        $this->assertSame([
            'bytes' => 10,
            'file_count' => 1,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.control_plane_receipts'));
        $this->assertSame([
            'bytes' => 80,
            'file_count' => 1,
            'directory_count' => 5,
        ], data_get($payload, 'by_category.quarantine_live_roots'));
        $this->assertSame([
            'bytes' => 40,
            'file_count' => 1,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.v2_materialized_cache'));
        $this->assertSame([
            'bytes' => 25,
            'file_count' => 1,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.offload_blob_copies'));
        $this->assertSame([
            'bytes' => 10,
            'file_count' => 1,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.framework_cache_or_sessions'));
        $this->assertSame([
            'bytes' => 10,
            'file_count' => 1,
            'directory_count' => 1,
        ], data_get($payload, 'by_category.logs'));
        $this->assertSame([
            'bytes' => 120,
            'file_count' => 2,
            'directory_count' => 2,
        ], data_get($payload, 'by_category.runtime_or_data_truth'));
        $this->assertSame([
            'bytes' => 15,
            'file_count' => 1,
            'directory_count' => 5,
        ], data_get($payload, 'by_category.unknown'));

        $this->assertSame([
            'quarantine_live_roots',
            'runtime_or_data_truth',
        ], $payload['no_touch_categories']);

        $this->assertSame('runtime_or_data_truth', data_get($payload, 'top_directories.0.category'));
        $this->assertSame('control_plane_snapshots', data_get($payload, 'reclaim_candidates.1.category'));
        $this->assertSame('low', data_get($payload, 'reclaim_candidates.1.risk_level'));
        $this->assertSame('janitor snapshots', data_get($payload, 'reclaim_candidates.1.safe_next_action'));
        $this->assertSame('unknown', data_get($payload, 'reclaim_candidates.4.category'));
        $this->assertSame('inspect before action', data_get($payload, 'reclaim_candidates.4.safe_next_action'));
        $this->assertSame('control_plane_snapshots', data_get($payload, 'suggested_next_actions.0.category'));
        $this->assertSame('control_plane_plans', data_get($payload, 'suggested_next_actions.1.category'));
        $this->assertSame('logs', data_get($payload, 'suggested_next_actions.2.category'));
        $this->assertSame('v2_materialized_cache', data_get($payload, 'suggested_next_actions.3.category'));

        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
    }

    private function seedStorageTreeForAnalysis(): void
    {
        $this->writeSizedFile('app/private/control_plane_snapshots/snapshot-1.json', 30);
        $this->writeSizedFile('app/private/prune_plans/reports/plan-1.json', 20);
        $this->writeSizedFile('app/private/quarantine/restore_runs/run-1/receipt.json', 10);
        $this->writeSizedFile('app/private/quarantine/release_roots/run-1/items/item-1/root/live.bin', 80);
        $this->writeSizedFile('app/private/packs_v2_materialized/cache-a/payload.bin', 40);
        $this->writeSizedFile('app/private/offload/blobs/aa/blob-copy.bin', 25);
        $this->writeSizedFile('framework/cache/data.bin', 10);
        $this->writeSizedFile('logs/laravel.log', 10);
        $this->writeSizedFile('app/private/reports/report-a.json', 90);
        $this->writeSizedFile('app/private/custom_unknown/data.bin', 15);
        $this->writeSizedFile('app/private/blobs/runtime.bin', 30);
    }

    private function writeSizedFile(string $relativePath, int $bytes): void
    {
        $path = storage_path($relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, str_repeat('x', $bytes));
    }

    /**
     * @return list<string>
     */
    private function storageFilesSnapshot(): array
    {
        if (! is_dir($this->isolatedStoragePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->isolatedStoragePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $files[] = str_replace($this->isolatedStoragePath.DIRECTORY_SEPARATOR, '', $file->getPathname());
        }

        sort($files);

        return $files;
    }
}
