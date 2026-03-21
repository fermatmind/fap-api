<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\StorageControlPlaneRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneRefreshServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-refresh-service-'.Str::uuid();
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

    public function test_service_runs_allowlisted_commands_in_fixed_order_and_records_single_batch_audit(): void
    {
        $filesBefore = $this->storageFilesSnapshot();
        $payload = $this->makeServiceForSequence($this->successfulSequence())->run();

        $this->assertSame('storage_refresh_control_plane.v1', $payload['schema']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('success', $payload['status']);
        $this->assertNull($payload['failed_step']);
        $this->assertSame(12, $payload['step_count']);
        $this->assertSame('/tmp/control-plane-snapshot.json', $payload['snapshot_path']);
        $this->assertCount(12, $payload['steps']);
        $this->assertSame('inventory', data_get($payload, 'steps.0.name'));
        $this->assertSame('control_plane_snapshot', data_get($payload, 'steps.11.name'));
        $this->assertSame('storage:control-plane-snapshot --json', data_get($payload, 'steps.11.invocation'));

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_refresh_control_plane',
            'target_id' => 'control_plane_refresh',
            'result' => 'success',
        ]);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame('success', $meta['status'] ?? null);
        $this->assertSame('/tmp/control-plane-snapshot.json', $meta['snapshot_path'] ?? null);
        $this->assertCount(12, (array) ($meta['steps'] ?? []));

        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertSame([], $this->existingFilesUnder('app/private/refresh_runs'));
        $this->assertSame([], $this->existingFilesUnder('app/private/refresh_plans'));
        $this->assertSame([], $this->existingFilesUnder('app/private/blobs'));
    }

    public function test_service_stops_on_first_failure_and_skips_snapshot(): void
    {
        $filesBefore = $this->storageFilesSnapshot();
        $payload = $this->makeServiceForSequence([
            ['storage:inventory', ['--json' => true], 0, "{\"status\":\"ok\"}\n"],
            ['storage:prune', ['--dry-run' => true, '--scope' => 'reports_backups'], 0, "status=planned\nscope=reports_backups\n"],
            ['storage:prune', ['--dry-run' => true, '--scope' => 'content_releases_retention'], 1, "status=failure\nerror=boom\n"],
        ])->run();

        $this->assertSame('failure', $payload['status']);
        $this->assertSame('prune_content_releases_retention', $payload['failed_step']);
        $this->assertNull($payload['snapshot_path']);
        $this->assertCount(3, $payload['steps']);
        $this->assertSame('failure', data_get($payload, 'steps.2.status'));
        $this->assertSame([], $this->existingFilesUnder('app/private/control_plane_snapshots'));

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_refresh_control_plane',
            'target_id' => 'control_plane_refresh',
            'result' => 'failure',
        ]);

        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertSame([], $this->existingFilesUnder('app/private/refresh_runs'));
        $this->assertSame([], $this->existingFilesUnder('app/private/refresh_plans'));
        $this->assertSame([], $this->existingFilesUnder('app/private/blobs'));
    }

    /**
     * @param  list<array{0:string,1:array<string,mixed>,2:int,3:string}>  $sequence
     */
    private function makeServiceForSequence(array $sequence): StorageControlPlaneRefreshService
    {
        $index = 0;

        return new StorageControlPlaneRefreshService(
            function (string $command, array $arguments) use (&$index, $sequence): array {
                if (! array_key_exists($index, $sequence)) {
                    self::fail('unexpected extra command invocation: '.$command);
                }

                [$expectedCommand, $expectedArguments, $exitCode, $stdout] = $sequence[$index];
                self::assertSame($expectedCommand, $command);
                self::assertSame($expectedArguments, $arguments);
                $index++;

                return [
                    'exit_code' => $exitCode,
                    'stdout' => $stdout,
                ];
            }
        );
    }

    /**
     * @return list<array{0:string,1:array<string,mixed>,2:int,3:string}>
     */
    private function successfulSequence(): array
    {
        return [
            ['storage:inventory', ['--json' => true], 0, "{\"schema_version\":\"storage_inventory.v1\"}\n"],
            ['storage:prune', ['--dry-run' => true, '--scope' => 'reports_backups'], 0, "status=planned\nscope=reports_backups\n"],
            ['storage:prune', ['--dry-run' => true, '--scope' => 'content_releases_retention'], 0, "status=planned\nscope=content_releases_retention\n"],
            ['storage:prune', ['--dry-run' => true, '--scope' => 'legacy_private_private_cleanup'], 0, "status=planned\nscope=legacy_private_private_cleanup\n"],
            ['storage:blob-gc', ['--dry-run' => true], 0, "status=planned\nplan=/tmp/blob-gc.json\n"],
            ['storage:blob-offload', ['--dry-run' => true], 0, "status=planned\nplan=/tmp/blob-offload.json\n"],
            ['storage:backfill-release-metadata', ['--dry-run' => true], 0, "status=planned\nmode=dry_run\n"],
            ['storage:backfill-exact-release-file-sets', ['--dry-run' => true], 0, "status=planned\nmode=dry_run\n"],
            ['storage:quarantine-exact-roots', ['--dry-run' => true], 0, "status=planned\nplan=/tmp/quarantine-plan.json\n"],
            ['storage:retire-exact-roots', ['--dry-run' => true, '--action' => 'quarantine'], 0, "status=planned\naction=quarantine\n"],
            ['storage:retire-exact-roots', ['--dry-run' => true, '--action' => 'purge'], 0, "status=planned\naction=purge\n"],
            ['storage:control-plane-snapshot', ['--json' => true], 0, "{\"status\":\"snapshotted\",\"snapshot_path\":\"/tmp/control-plane-snapshot.json\"}\n"],
        ];
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
