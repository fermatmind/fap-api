<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageJanitorMaterializedCache;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageJanitorMaterializedCacheCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-materialized-janitor-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageJanitorMaterializedCache::class)
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

    public function test_command_dry_run_outputs_summary_contract_without_deleting_files(): void
    {
        $fixture = $this->seedLocalCandidateBucket();
        $filesBefore = $this->storageFilesSnapshot();

        $this->assertSame(0, Artisan::call('storage:janitor-materialized-cache', [
            '--dry-run' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('scanned_bucket_count=1', $output);
        $this->assertStringContainsString('candidate_delete_count=1', $output);
        $this->assertStringContainsString('skipped_bucket_count=0', $output);
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertDirectoryExists($fixture['bucket_root']);
        $this->assertPlanRunReceiptTreesDoNotExist();
    }

    public function test_command_execute_outputs_summary_contract_and_deletes_whole_bucket(): void
    {
        $fixture = $this->seedLocalCandidateBucket();

        $this->assertSame(0, Artisan::call('storage:janitor-materialized-cache', [
            '--execute' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=executed', $output);
        $this->assertStringContainsString('mode=execute', $output);
        $this->assertStringContainsString('deleted_bucket_count=1', $output);
        $this->assertStringContainsString('skipped_bucket_count=0', $output);
        $this->assertDirectoryDoesNotExist($fixture['bucket_root']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_materialized_cache',
            'target_id' => 'materialized_cache',
            'result' => 'success',
        ]);
        $this->assertPlanRunReceiptTreesDoNotExist();
    }

    public function test_command_json_outputs_full_payload(): void
    {
        $fixture = $this->seedLocalCandidateBucket();

        $this->assertSame(0, Artisan::call('storage:janitor-materialized-cache', [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('storage_janitor_materialized_cache.v1', $payload['schema']);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(1, data_get($payload, 'summary.scanned_bucket_count'));
        $this->assertSame($fixture['bucket_root'], data_get($payload, 'candidates.0.bucket_root'));
        $this->assertSame('local', data_get($payload, 'candidates.0.proof_kind'));
    }

    public function test_command_fails_when_mode_is_missing(): void
    {
        $this->assertSame(1, Artisan::call('storage:janitor-materialized-cache'));
        $this->assertStringContainsString('exactly one of --dry-run or --execute is required.', Artisan::output());
    }

    public function test_command_fails_when_both_modes_are_passed(): void
    {
        $this->assertSame(1, Artisan::call('storage:janitor-materialized-cache', [
            '--dry-run' => true,
            '--execute' => true,
        ]));
        $this->assertStringContainsString('exactly one of --dry-run or --execute is required.', Artisan::output());
    }

    /**
     * @return array<string,string>
     */
    private function seedLocalCandidateBucket(): array
    {
        $releaseId = (string) Str::uuid();
        $manifestPayload = json_encode([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => hash('sha256', 'compiled|command'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($manifestPayload);

        $manifestHash = hash('sha256', $manifestPayload);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => 'test',
            'created_by' => 'test',
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $manifestHash,
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => null,
            'pack_version' => 'v1',
            'manifest_json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');
        File::ensureDirectoryExists($sourceCompiledDir);
        File::put($sourceCompiledDir.'/manifest.json', $manifestPayload);
        File::put($sourceCompiledDir.'/questions.compiled.json', '{"source":"command"}');

        $bucketRoot = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.hash('sha256', $storagePath).'/'.$manifestHash);
        File::ensureDirectoryExists($bucketRoot.'/compiled');
        File::put($bucketRoot.'/.materialization.json', json_encode([
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
            'source_compiled_dir' => $sourceCompiledDir,
            'materialized_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
        File::put($bucketRoot.'/compiled/manifest.json', $manifestPayload);
        File::put($bucketRoot.'/compiled/questions.compiled.json', '{"source":"command"}');

        return [
            'bucket_root' => $bucketRoot,
        ];
    }

    private function assertPlanRunReceiptTreesDoNotExist(): void
    {
        $this->assertDirectoryDoesNotExist(storage_path('app/private/gc_plans'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/rehydrate_plans'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/quarantine_plans'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/retirement_plans'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/rehydrate_runs'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/quarantine/restore_runs'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/quarantine/purge_runs'));
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
