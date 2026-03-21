<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageControlPlaneStatus;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneStatusCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.resolver_materialization_enabled', false);
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);
        Storage::forgetDisk('local');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageControlPlaneStatus::class)
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

    public function test_command_outputs_full_json_without_mutation_or_audit_inserts(): void
    {
        $this->seedMinimalTruth();

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $this->assertSame(0, Artisan::call('storage:control-plane-status', [
            '--json' => true,
        ]));

        $output = trim(Artisan::output());
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('storage_control_plane_status.v1', $payload['schema_version']);
        $this->assertArrayHasKey('inventory', $payload);
        $this->assertArrayHasKey('retention', $payload);
        $this->assertArrayHasKey('blob_coverage', $payload);
        $this->assertArrayHasKey('exact_authority', $payload);
        $this->assertArrayHasKey('rehydrate', $payload);
        $this->assertArrayHasKey('quarantine', $payload);
        $this->assertArrayHasKey('restore', $payload);
        $this->assertArrayHasKey('purge', $payload);
        $this->assertArrayHasKey('retirement', $payload);
        $this->assertArrayHasKey('materialized_cache', $payload);
        $this->assertArrayHasKey('runtime_truth', $payload);
        $this->assertArrayHasKey('automation_readiness', $payload);
        $this->assertArrayHasKey('attention_digest', $payload);
        $this->assertSame('ok', data_get($payload, 'inventory.status'));
        $this->assertArrayHasKey('last_updated_at', $payload['inventory']);
        $this->assertArrayHasKey('freshness_age_seconds', $payload['inventory']);
        $this->assertArrayHasKey('freshness_state', $payload['inventory']);
        $this->assertArrayHasKey('freshness_source_type', $payload['inventory']);
        $this->assertArrayHasKey('last_updated_at', data_get($payload, 'retention.scopes.reports_backups', []));
        $this->assertArrayHasKey('freshness_age_seconds', data_get($payload, 'retention.scopes.reports_backups', []));
        $this->assertArrayHasKey('freshness_state', data_get($payload, 'retention.scopes.reports_backups', []));
        $this->assertArrayHasKey('freshness_source_type', data_get($payload, 'retention.scopes.reports_backups', []));
        $this->assertSame('remote_rehydrate_enabled', data_get($payload, 'runtime_truth.v2_readiness'));
        $this->assertSame('ok', data_get($payload, 'materialized_cache.status'));
        $this->assertSame(storage_path('app/private/packs_v2_materialized'), data_get($payload, 'materialized_cache.root_path'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.bucket_count'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.total_files'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.total_bytes'));
        $this->assertSame([], data_get($payload, 'materialized_cache.sample_bucket_paths'));
        $this->assertSame('storage_path + manifest_hash', data_get($payload, 'materialized_cache.cache_key_contract'));
        $this->assertSame('derived_cache_return_surface', data_get($payload, 'materialized_cache.runtime_role'));
        $this->assertFalse((bool) data_get($payload, 'materialized_cache.source_of_truth'));
        $this->assertTrue((bool) data_get($payload, 'materialized_cache.zero_state'));
        $this->assertNull(data_get($payload, 'materialized_cache.last_updated_at'));
        $this->assertNull(data_get($payload, 'materialized_cache.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'materialized_cache.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'materialized_cache.freshness_source_type'));
        $this->assertSame('unknown_freshness', data_get($payload, 'runtime_truth.freshness_state'));
        $this->assertSame('config-derived', data_get($payload, 'runtime_truth.freshness_source_type'));
        $this->assertSame('unknown_freshness', data_get($payload, 'automation_readiness.freshness_state'));
        $this->assertSame('config-derived', data_get($payload, 'automation_readiness.freshness_source_type'));
        $this->assertSame('attention_required', data_get($payload, 'attention_digest.overall_state'));
        $this->assertContains('retention.scopes.reports_backups', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertContains('rehydrate', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertSame([], data_get($payload, 'attention_digest.not_available_sections'));
        $this->assertSame('reports backups retention dry-run has never run', data_get($payload, 'attention_digest.attention_items.0.message'));

        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
    }

    public function test_command_json_reports_non_zero_materialized_cache_state(): void
    {
        $this->seedMinimalTruth();
        $bucket = [
            '.materialization.json' => json_encode([
                'storage_path' => 'private/packs_v2/BIG5_OCEAN/v1/release-a',
                'manifest_hash' => str_repeat('b', 64),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'compiled/manifest.json' => str_repeat('m', 12),
            'compiled/questions.compiled.json' => str_repeat('q', 8),
        ];
        $this->seedMaterializedBucket('BIG5_OCEAN', 'v1', str_repeat('a', 64), str_repeat('b', 64), $bucket);

        $this->assertSame(0, Artisan::call('storage:control-plane-status', [
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($payload);
        $this->assertSame(1, data_get($payload, 'materialized_cache.bucket_count'));
        $this->assertSame(3, data_get($payload, 'materialized_cache.total_files'));
        $this->assertSame($this->totalBytesForFiles($bucket), data_get($payload, 'materialized_cache.total_bytes'));
        $this->assertSame([
            str_replace('\\', '/', storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64))),
        ], data_get($payload, 'materialized_cache.sample_bucket_paths'));
        $this->assertFalse((bool) data_get($payload, 'materialized_cache.zero_state'));
        $this->assertNull(data_get($payload, 'materialized_cache.last_updated_at'));
        $this->assertNull(data_get($payload, 'materialized_cache.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'materialized_cache.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'materialized_cache.freshness_source_type'));
    }

    public function test_command_outputs_human_readable_summary(): void
    {
        $this->seedMinimalTruth();

        $this->assertSame(0, Artisan::call('storage:control-plane-status'));

        $output = Artisan::output();
        $this->assertStringContainsString('schema_version=storage_control_plane_status.v1', $output);
        $this->assertStringContainsString('inventory.status=ok', $output);
        $this->assertStringContainsString('runtime_truth.v2_readiness=remote_rehydrate_enabled', $output);
        $this->assertStringContainsString('automation_readiness.auto_dry_run_ok=', $output);
    }

    private function seedMinimalTruth(): void
    {
        $now = now();

        $this->writeJson(storage_path('app/private/quarantine/release_roots/run-a/items/item-a/root/.quarantine.json'), [
            'source_kind' => 'legacy.source_pack',
        ]);
        $this->writeJson(storage_path('app/private/quarantine/purge_runs/purge-a/items/item-a/purge.json'), [
            'status' => 'success',
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_inventory',
            'target_type' => 'storage',
            'target_id' => 'inventory',
            'meta_json' => json_encode([
                'schema_version' => 2,
                'generated_at' => $now->toIso8601String(),
                'focus_scopes' => ['reports'],
                'totals' => ['files' => 1, 'bytes' => 64],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_status',
            'request_id' => null,
            'reason' => 'seed',
            'result' => 'success',
            'created_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
    }

    /**
     * @param  array<string,string>  $files
     */
    private function seedMaterializedBucket(
        string $packId,
        string $packVersion,
        string $storageIdentity,
        string $manifestHash,
        array $files,
    ): void {
        $root = storage_path('app/private/packs_v2_materialized/'.$packId.'/'.$packVersion.'/'.$storageIdentity.'/'.$manifestHash);

        foreach ($files as $relativePath => $contents) {
            $absolutePath = $root.'/'.ltrim($relativePath, '/');
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $contents);
        }
    }

    /**
     * @param  array<string,string>  $files
     */
    private function totalBytesForFiles(array $files): int
    {
        return array_sum(array_map(static fn (string $contents): int => strlen($contents), $files));
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
