<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageControlPlaneArtifactsJanitor;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneArtifactsJanitorCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-janitor-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_retention.control_plane_artifacts.control_plane_snapshots.keep_last_n', 1);
        config()->set('storage_retention.control_plane_artifacts.plan_dirs.keep_last_n', 1);
        config()->set('storage_retention.control_plane_artifacts.prune_plans.keep_last_n_per_scope', 1);
        config()->set('storage_retention.control_plane_artifacts.retain_latest_audit_referenced', true);
        Storage::forgetDisk('local');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageControlPlaneArtifactsJanitor::class)
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

    public function test_command_dry_run_outputs_summary_and_keeps_files_intact(): void
    {
        $fixture = $this->seedArtifacts();
        $filesBefore = $this->storageFilesSnapshot();
        $auditCountBefore = DB::table('audit_logs')->count();

        $this->assertSame(0, Artisan::call('storage:janitor-control-plane-artifacts', [
            '--dry-run' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('candidate_delete_count=', $output);
        $this->assertStringContainsString('deleted_file_count=0', $output);

        $this->assertSame($auditCountBefore + 1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_control_plane_artifacts',
            'target_id' => 'control_plane_artifacts',
            'result' => 'success',
        ]);
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertFileExists($fixture['snapshot_old']);
        $this->assertFileExists($fixture['quarantine_sentinel']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_command_execute_deletes_only_candidate_artifacts_and_writes_audit(): void
    {
        $fixture = $this->seedArtifacts();

        $this->assertSame(0, Artisan::call('storage:janitor-control-plane-artifacts', [
            '--execute' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=executed', $output);
        $this->assertStringContainsString('mode=execute', $output);
        $this->assertStringContainsString('deleted_file_count=', $output);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_control_plane_artifacts',
            'target_id' => 'control_plane_artifacts',
            'result' => 'success',
        ]);

        $this->assertFileDoesNotExist($fixture['snapshot_old']);
        $this->assertFileDoesNotExist($fixture['gc_old']);
        $this->assertFileDoesNotExist($fixture['prune_reports_old']);
        $this->assertFileExists($fixture['snapshot_audit']);
        $this->assertFileExists($fixture['snapshot_latest']);
        $this->assertFileExists($fixture['quarantine_sentinel']);
        $this->assertFileExists($fixture['restore_run']);
        $this->assertFileExists($fixture['purge_receipt']);
        $this->assertFileExists($fixture['retirement_run']);
        $this->assertFileExists($fixture['rehydrate_sentinel']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    /**
     * @return array<string,string>
     */
    private function seedArtifacts(): array
    {
        $now = now()->getTimestamp();
        $paths = [
            'snapshot_old' => $this->writeJsonAt('app/private/control_plane_snapshots/20260318_snapshot_old.json', ['kind' => 'snapshot_old'], $now - 500),
            'snapshot_audit' => $this->writeJsonAt('app/private/control_plane_snapshots/20260319_snapshot_audit.json', ['kind' => 'snapshot_audit'], $now - 400),
            'snapshot_latest' => $this->writeJsonAt('app/private/control_plane_snapshots/20260320_snapshot_latest.json', ['kind' => 'snapshot_latest'], $now - 100),
            'gc_old' => $this->writeJsonAt('app/private/gc_plans/20260318_gc_old.json', ['kind' => 'gc_old'], $now - 500),
            'gc_audit' => $this->writeJsonAt('app/private/gc_plans/20260319_gc_audit.json', ['kind' => 'gc_audit'], $now - 100),
            'prune_reports_old' => $this->writeJsonAt('app/private/prune_plans/20260318_reports_old.json', ['scope' => 'reports_backups'], $now - 500),
            'prune_reports_audit' => $this->writeJsonAt('app/private/prune_plans/20260319_reports_audit.json', ['scope' => 'reports_backups'], $now - 400),
            'prune_reports_latest' => $this->writeJsonAt('app/private/prune_plans/20260320_reports_latest.json', ['scope' => 'reports_backups'], $now - 100),
            'quarantine_sentinel' => $this->writeJsonAt('app/private/quarantine/release_roots/run-1/items/item-1/root/.quarantine.json', ['schema' => 'storage_quarantine_exact_root_run.v1'], $now - 100),
            'restore_run' => $this->writeJsonAt('app/private/quarantine/restore_runs/restore-1/run.json', ['schema' => 'storage_restore_quarantined_root_run.v1'], $now - 100),
            'purge_receipt' => $this->writeJsonAt('app/private/quarantine/purge_runs/purge-1/items/item-1/purge.json', ['schema' => 'storage_purge_quarantined_root_run.v1'], $now - 100),
            'retirement_run' => $this->writeJsonAt('app/private/retirement_runs/retire-1/run.json', ['schema' => 'storage_retire_exact_roots_run.v1'], $now - 100),
            'rehydrate_sentinel' => $this->writeJsonAt('app/private/rehydrate_runs/rehydrate-1/.rehydrate.json', ['schema' => 'storage_rehydrate_exact_release_plan.v1'], $now - 100),
        ];

        $this->insertAudit('storage_control_plane_snapshot', 'control_plane_snapshot', [
            'snapshot_path' => $paths['snapshot_audit'],
        ]);
        $this->insertAudit('storage_blob_gc', 'blob_gc', [
            'plan' => $paths['gc_audit'],
        ]);
        $this->insertAudit('storage_prune', 'reports_backups', [
            'scope' => 'reports_backups',
            'plan' => $paths['prune_reports_audit'],
        ]);

        return $paths;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function insertAudit(string $action, string $targetId, array $payload): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => 'storage',
            'target_id' => $targetId,
            'meta_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_artifacts_janitor_command',
            'request_id' => null,
            'reason' => 'seed',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJsonAt(string $relativePath, array $payload, int $mtime): string
    {
        $path = storage_path($relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
        touch($path, $mtime);

        return $path;
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
