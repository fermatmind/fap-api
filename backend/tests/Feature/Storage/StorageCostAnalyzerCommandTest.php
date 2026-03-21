<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageCostAnalyzer;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageCostAnalyzerCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-cost-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageCostAnalyzer::class)
        );
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

    public function test_command_emits_json_payload_without_side_effects(): void
    {
        $this->writeSizedFile('app/private/control_plane_snapshots/snapshot.json', 12);
        $this->writeSizedFile('logs/laravel.log', 5);
        $this->writeSizedFile('app/private/blobs/runtime.bin', 18);

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $this->assertSame(0, Artisan::call('storage:analyze-cost', [
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('storage_cost_analyzer.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame($this->isolatedStoragePath, $payload['root_path']);
        $this->assertSame(35, data_get($payload, 'summary.total_bytes'));
        $this->assertSame('runtime_or_data_truth', data_get($payload, 'summary.largest_category'));
        $this->assertSame('app/private/blobs', data_get($payload, 'top_directories.0.path'));

        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
    }

    public function test_command_emits_summary_output(): void
    {
        $this->writeSizedFile('app/private/packs_v2_materialized/cache/item.bin', 22);
        $this->writeSizedFile('logs/laravel.log', 9);

        $this->assertSame(0, Artisan::call('storage:analyze-cost'));

        $output = Artisan::output();
        $this->assertStringContainsString('status=ok', $output);
        $this->assertStringContainsString('root='.$this->isolatedStoragePath, $output);
        $this->assertStringContainsString('total_bytes=31', $output);
        $this->assertStringContainsString('largest_category=v2_materialized_cache', $output);
        $this->assertStringContainsString('largest_category_bytes=22', $output);
        $this->assertStringContainsString('top_directory=app/private/packs_v2_materialized', $output);
        $this->assertStringContainsString('top_directory_bytes=22', $output);
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
