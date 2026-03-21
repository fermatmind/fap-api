<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageControlPlaneSnapshot;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneSnapshotCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-snapshot-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.resolver_materialization_enabled', false);
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);
        Storage::forgetDisk('local');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageControlPlaneSnapshot::class)
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

    public function test_command_outputs_full_json_and_persists_snapshot(): void
    {
        $this->seedMinimalTruth();

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $this->assertSame(0, Artisan::call('storage:control-plane-snapshot', [
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($payload);
        $this->assertSame('storage_control_plane_snapshot.v1', $payload['snapshot_schema']);
        $this->assertSame('snapshotted', $payload['status']);
        $this->assertSame('ok', data_get($payload, 'inventory.status'));
        $this->assertSame('remote_rehydrate_enabled', data_get($payload, 'runtime_truth.v2_readiness'));
        $this->assertFileExists((string) $payload['snapshot_path']);
        $this->assertSame($auditCountBefore + 1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_control_plane_snapshot',
            'target_id' => 'control_plane_snapshot',
            'result' => 'success',
        ]);

        $filesAfter = $this->storageFilesSnapshot();
        $newFiles = array_values(array_diff($filesAfter, $filesBefore));
        $this->assertCount(1, $newFiles);
        $this->assertStringStartsWith('app/private/control_plane_snapshots/', $newFiles[0]);
        $this->assertSame([], $this->existingFilesUnder('app/private/prune_plans'));
        $this->assertSame([], $this->existingFilesUnder('app/private/retirement_runs'));
        $this->assertSame([], $this->existingFilesUnder('app/private/blobs'));
    }

    public function test_command_outputs_human_readable_summary(): void
    {
        $this->seedMinimalTruth();

        $this->assertSame(0, Artisan::call('storage:control-plane-snapshot'));

        $output = Artisan::output();
        $this->assertStringContainsString('status=snapshotted', $output);
        $this->assertStringContainsString('snapshot=', $output);
        $this->assertStringContainsString('schema_version=storage_control_plane_snapshot.v1', $output);
        $this->assertStringContainsString('generated_at=', $output);
    }

    private function seedMinimalTruth(): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_inventory',
            'target_type' => 'storage',
            'target_id' => 'inventory',
            'meta_json' => json_encode([
                'schema_version' => 2,
                'generated_at' => now()->toIso8601String(),
                'focus_scopes' => ['reports'],
                'totals' => ['files' => 1, 'bytes' => 64],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_snapshot_command',
            'request_id' => null,
            'reason' => 'seed',
            'result' => 'success',
            'created_at' => now(),
        ]);
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

    /**
     * @return list<string>
     */
    private function existingFilesUnder(string $relativeDir): array
    {
        $dir = $this->isolatedStoragePath.'/'.$relativeDir;
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
