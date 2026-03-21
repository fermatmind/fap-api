<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\StorageControlPlaneArtifactsJanitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneArtifactsJanitorServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-janitor-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_retention.control_plane_artifacts.control_plane_snapshots.keep_last_n', 1);
        config()->set('storage_retention.control_plane_artifacts.plan_dirs.keep_last_n', 1);
        config()->set('storage_retention.control_plane_artifacts.prune_plans.keep_last_n_per_scope', 1);
        config()->set('storage_retention.control_plane_artifacts.retain_latest_audit_referenced', true);
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

    public function test_service_dry_run_only_marks_allowed_json_artifacts_and_keeps_protected_files(): void
    {
        $fixture = $this->seedArtifacts();
        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $payload = app(StorageControlPlaneArtifactsJanitorService::class)->run(false);

        $this->assertSame('storage_control_plane_artifacts_janitor.v1', $payload['schema']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame($auditCountBefore + 1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_control_plane_artifacts',
            'target_id' => 'control_plane_artifacts',
            'result' => 'success',
        ]);

        $this->assertContains($fixture['paths']['snapshot_audit'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['snapshot_latest'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['gc_audit'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['quarantine_plan_audit'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['retirement_plan_audit'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['prune_reports_audit'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['prune_reports_latest'], $payload['kept_paths']);
        $this->assertContains($fixture['paths']['prune_content_latest'], $payload['kept_paths']);

        $this->assertContains($fixture['paths']['snapshot_old'], $payload['candidate_delete_paths']);
        $this->assertContains($fixture['paths']['gc_old'], $payload['candidate_delete_paths']);
        $this->assertContains($fixture['paths']['offload_old'], $payload['candidate_delete_paths']);
        $this->assertContains($fixture['paths']['prune_reports_old'], $payload['candidate_delete_paths']);
        $this->assertContains($fixture['paths']['prune_content_old'], $payload['candidate_delete_paths']);
        $this->assertSame([], $payload['deleted_paths']);

        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertFileExists($fixture['paths']['quarantine_sentinel']);
        $this->assertFileExists($fixture['paths']['quarantine_run']);
        $this->assertFileExists($fixture['paths']['restore_run']);
        $this->assertFileExists($fixture['paths']['purge_run']);
        $this->assertFileExists($fixture['paths']['purge_receipt']);
        $this->assertFileExists($fixture['paths']['retirement_run']);
        $this->assertFileExists($fixture['paths']['rehydrate_sentinel']);
        $this->assertFileExists($fixture['paths']['offload_blob']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
        $this->assertSame(0, DB::table('content_pack_releases')->count());
    }

    public function test_service_execute_deletes_only_candidate_json_artifacts(): void
    {
        $fixture = $this->seedArtifacts();

        $payload = app(StorageControlPlaneArtifactsJanitorService::class)->run(true);

        $this->assertSame('execute', $payload['mode']);
        $this->assertSame('executed', $payload['status']);
        $this->assertGreaterThan(0, (int) data_get($payload, 'summary.deleted_file_count', 0));
        $this->assertContains($fixture['paths']['snapshot_old'], $payload['deleted_paths']);
        $this->assertContains($fixture['paths']['gc_old'], $payload['deleted_paths']);
        $this->assertContains($fixture['paths']['offload_old'], $payload['deleted_paths']);
        $this->assertContains($fixture['paths']['prune_reports_old'], $payload['deleted_paths']);
        $this->assertContains($fixture['paths']['prune_content_old'], $payload['deleted_paths']);

        $this->assertFileDoesNotExist($fixture['paths']['snapshot_old']);
        $this->assertFileDoesNotExist($fixture['paths']['gc_old']);
        $this->assertFileDoesNotExist($fixture['paths']['offload_old']);
        $this->assertFileDoesNotExist($fixture['paths']['prune_reports_old']);
        $this->assertFileDoesNotExist($fixture['paths']['prune_content_old']);

        $this->assertFileExists($fixture['paths']['snapshot_audit']);
        $this->assertFileExists($fixture['paths']['snapshot_latest']);
        $this->assertFileExists($fixture['paths']['gc_audit']);
        $this->assertFileExists($fixture['paths']['quarantine_plan_audit']);
        $this->assertFileExists($fixture['paths']['retirement_plan_audit']);
        $this->assertFileExists($fixture['paths']['prune_reports_audit']);
        $this->assertFileExists($fixture['paths']['prune_reports_latest']);
        $this->assertFileExists($fixture['paths']['prune_content_latest']);
        $this->assertFileExists($fixture['paths']['quarantine_sentinel']);
        $this->assertFileExists($fixture['paths']['rehydrate_sentinel']);
        $this->assertFileExists($fixture['paths']['restore_run']);
        $this->assertFileExists($fixture['paths']['purge_receipt']);
        $this->assertFileExists($fixture['paths']['retirement_run']);
        $this->assertFileExists($fixture['paths']['offload_blob']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    /**
     * @return array{paths:array<string,string>}
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
            'offload_old' => $this->writeJsonAt('app/private/offload_plans/20260318_offload_old.json', ['kind' => 'offload_old'], $now - 500),
            'offload_latest' => $this->writeJsonAt('app/private/offload_plans/20260319_offload_latest.json', ['kind' => 'offload_latest'], $now - 100),
            'rehydrate_old' => $this->writeJsonAt('app/private/rehydrate_plans/20260318_rehydrate_old.json', ['kind' => 'rehydrate_old'], $now - 500),
            'rehydrate_latest' => $this->writeJsonAt('app/private/rehydrate_plans/20260319_rehydrate_latest.json', ['kind' => 'rehydrate_latest'], $now - 100),
            'quarantine_plan_audit' => $this->writeJsonAt('app/private/quarantine_plans/20260318_quarantine_audit.json', ['kind' => 'quarantine_audit'], $now - 500),
            'quarantine_plan_latest' => $this->writeJsonAt('app/private/quarantine_plans/20260319_quarantine_latest.json', ['kind' => 'quarantine_latest'], $now - 100),
            'restore_plan_old' => $this->writeJsonAt('app/private/quarantine_restore_plans/20260318_restore_old.json', ['kind' => 'restore_old'], $now - 500),
            'restore_plan_latest' => $this->writeJsonAt('app/private/quarantine_restore_plans/20260319_restore_latest.json', ['kind' => 'restore_latest'], $now - 100),
            'purge_plan_old' => $this->writeJsonAt('app/private/quarantine_purge_plans/20260318_purge_old.json', ['kind' => 'purge_old'], $now - 500),
            'purge_plan_latest' => $this->writeJsonAt('app/private/quarantine_purge_plans/20260319_purge_latest.json', ['kind' => 'purge_latest'], $now - 100),
            'retirement_plan_old' => $this->writeJsonAt('app/private/retirement_plans/20260318_retire_old.json', ['kind' => 'retire_old'], $now - 700),
            'retirement_plan_audit' => $this->writeJsonAt('app/private/retirement_plans/20260319_retire_audit.json', ['kind' => 'retire_audit'], $now - 500),
            'retirement_plan_latest' => $this->writeJsonAt('app/private/retirement_plans/20260320_retire_latest.json', ['kind' => 'retire_latest'], $now - 100),
            'prune_reports_old' => $this->writeJsonAt('app/private/prune_plans/20260318_reports_old.json', ['scope' => 'reports_backups'], $now - 700),
            'prune_reports_audit' => $this->writeJsonAt('app/private/prune_plans/20260319_reports_audit.json', ['scope' => 'reports_backups'], $now - 500),
            'prune_reports_latest' => $this->writeJsonAt('app/private/prune_plans/20260320_reports_latest.json', ['scope' => 'reports_backups'], $now - 100),
            'prune_content_old' => $this->writeJsonAt('app/private/prune_plans/20260318_content_old.json', ['scope' => 'content_releases_retention'], $now - 500),
            'prune_content_latest' => $this->writeJsonAt('app/private/prune_plans/20260319_content_latest.json', ['scope' => 'content_releases_retention'], $now - 100),
            'quarantine_run' => $this->writeJsonAt('app/private/quarantine/release_roots/run-1/run.json', ['schema' => 'storage_quarantine_exact_root_run.v1'], $now - 100),
            'quarantine_sentinel' => $this->writeJsonAt('app/private/quarantine/release_roots/run-1/items/item-1/root/.quarantine.json', ['schema' => 'storage_quarantine_exact_root_run.v1'], $now - 100),
            'restore_run' => $this->writeJsonAt('app/private/quarantine/restore_runs/restore-1/run.json', ['schema' => 'storage_restore_quarantined_root_run.v1'], $now - 100),
            'purge_run' => $this->writeJsonAt('app/private/quarantine/purge_runs/purge-1/run.json', ['schema' => 'storage_purge_quarantined_root_run.v1'], $now - 100),
            'purge_receipt' => $this->writeJsonAt('app/private/quarantine/purge_runs/purge-1/items/item-1/purge.json', ['schema' => 'storage_purge_quarantined_root_run.v1'], $now - 100),
            'retirement_run' => $this->writeJsonAt('app/private/retirement_runs/retire-1/run.json', ['schema' => 'storage_retire_exact_roots_run.v1'], $now - 100),
            'rehydrate_sentinel' => $this->writeJsonAt('app/private/rehydrate_runs/rehydrate-1/.rehydrate.json', ['schema' => 'storage_rehydrate_exact_release_plan.v1'], $now - 100),
        ];

        $offloadBlob = storage_path('app/private/offload/blobs/sha256/aa/blob-a');
        File::ensureDirectoryExists(dirname($offloadBlob));
        File::put($offloadBlob, 'blob-copy');
        touch($offloadBlob, $now - 100);
        $paths['offload_blob'] = $offloadBlob;

        $this->insertAudit('storage_control_plane_snapshot', 'control_plane_snapshot', [
            'snapshot_path' => $paths['snapshot_audit'],
        ]);
        $this->insertAudit('storage_blob_gc', 'blob_gc', [
            'plan' => $paths['gc_audit'],
        ]);
        $this->insertAudit('storage_quarantine_exact_roots', 'exact_roots', [
            'plan_path' => $paths['quarantine_plan_audit'],
        ]);
        $this->insertAudit('storage_retire_exact_roots', 'exact_roots', [
            'plan_path' => $paths['retirement_plan_audit'],
            'plan' => ['action' => 'quarantine'],
        ]);
        $this->insertAudit('storage_prune', 'reports_backups', [
            'scope' => 'reports_backups',
            'plan' => $paths['prune_reports_audit'],
        ]);

        return ['paths' => $paths];
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
            'user_agent' => 'test/storage_control_plane_artifacts_janitor_service',
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
