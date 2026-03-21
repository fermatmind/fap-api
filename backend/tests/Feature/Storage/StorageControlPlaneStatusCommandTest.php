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
        $this->assertArrayHasKey('runtime_truth', $payload);
        $this->assertArrayHasKey('automation_readiness', $payload);
        $this->assertSame('ok', data_get($payload, 'inventory.status'));
        $this->assertSame('remote_rehydrate_enabled', data_get($payload, 'runtime_truth.v2_readiness'));

        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
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
