<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\StorageControlPlaneStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneStatusServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-status-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.resolver_materialization_enabled', true);
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
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

    public function test_service_aggregates_existing_truth_without_mutation(): void
    {
        $this->seedControlPlaneTruth();

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $payload = app(StorageControlPlaneStatusService::class)->buildStatus();

        $this->assertSame('storage_control_plane_status.v1', $payload['schema_version']);
        $this->assertSame('ok', data_get($payload, 'inventory.status'));
        $this->assertSame('fresh', data_get($payload, 'inventory.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'inventory.freshness_source_type'));
        $this->assertIsString(data_get($payload, 'inventory.last_updated_at'));
        $this->assertIsInt(data_get($payload, 'inventory.freshness_age_seconds'));
        $this->assertSame('fresh', data_get($payload, 'retention.scopes.reports_backups.freshness_state'));
        $this->assertSame('mixed-derived', data_get($payload, 'retention.scopes.reports_backups.freshness_source_type'));
        $this->assertSame(['reports', 'artifacts'], data_get($payload, 'inventory.focus_scopes'));
        $this->assertSame(1, data_get($payload, 'blob_coverage.counts.storage_blobs'));
        $this->assertSame(1, data_get($payload, 'blob_coverage.counts.verified_storage_blob_locations_by_disk.s3'));
        $this->assertSame('fresh', data_get($payload, 'blob_coverage.blob_gc.freshness_state'));
        $this->assertSame('fresh', data_get($payload, 'blob_coverage.blob_offload.freshness_state'));
        $this->assertSame(1, data_get($payload, 'exact_authority.counts.content_release_exact_manifests'));
        $this->assertSame(1, data_get($payload, 'exact_authority.counts.content_release_exact_manifest_files'));
        $this->assertSame('fresh', data_get($payload, 'exact_authority.latest_backfill.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'exact_authority.latest_backfill.freshness_source_type'));
        $this->assertSame('fresh', data_get($payload, 'rehydrate.freshness_state'));
        $this->assertSame(1, data_get($payload, 'quarantine.item_root_count'));
        $this->assertSame('fresh', data_get($payload, 'quarantine.freshness_state'));
        $this->assertSame(1, data_get($payload, 'restore.restore_run_count'));
        $this->assertSame('fresh', data_get($payload, 'restore.freshness_state'));
        $this->assertSame(1, data_get($payload, 'purge.purge_receipt_count'));
        $this->assertSame('fresh', data_get($payload, 'purge.freshness_state'));
        $this->assertSame('ok', data_get($payload, 'retirement.actions.quarantine.status'));
        $this->assertSame('ok', data_get($payload, 'retirement.actions.purge.status'));
        $this->assertSame('fresh', data_get($payload, 'retirement.actions.quarantine.freshness_state'));
        $this->assertSame('fresh', data_get($payload, 'retirement.actions.purge.freshness_state'));
        $this->assertTrue((bool) data_get($payload, 'runtime_truth.resolver_materialization_enabled'));
        $this->assertFalse((bool) data_get($payload, 'runtime_truth.packs_v2_remote_rehydrate_enabled'));
        $this->assertSame('materialization_enabled_only', data_get($payload, 'runtime_truth.v2_readiness'));
        $this->assertSame('unknown_freshness', data_get($payload, 'runtime_truth.freshness_state'));
        $this->assertSame('config-derived', data_get($payload, 'runtime_truth.freshness_source_type'));
        $this->assertSame([
            'storage:inventory',
            'storage:prune',
            'storage:blob-gc',
            'storage:blob-offload',
            'storage:backfill-release-metadata',
            'storage:backfill-exact-release-file-sets',
            'storage:quarantine-exact-roots',
            'storage:retire-exact-roots',
        ], data_get($payload, 'automation_readiness.auto_dry_run_ok'));
        $this->assertSame('unknown_freshness', data_get($payload, 'automation_readiness.freshness_state'));
        $this->assertSame('config-derived', data_get($payload, 'automation_readiness.freshness_source_type'));
        $this->assertSame('healthy', data_get($payload, 'attention_digest.overall_state'));
        $this->assertSame([], data_get($payload, 'attention_digest.stale_sections'));
        $this->assertSame([], data_get($payload, 'attention_digest.never_run_sections'));
        $this->assertSame([], data_get($payload, 'attention_digest.not_available_sections'));
        $this->assertSame([
            'stale' => 0,
            'never_run' => 0,
            'not_available' => 0,
        ], data_get($payload, 'attention_digest.counts'));
        $this->assertSame([], data_get($payload, 'attention_digest.attention_items'));

        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
    }

    public function test_service_reports_missing_truth_explicitly(): void
    {
        $payload = app(StorageControlPlaneStatusService::class)->buildStatus();

        $this->assertSame('not_available', data_get($payload, 'inventory.status'));
        $this->assertSame('not_available', data_get($payload, 'inventory.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retention.status'));
        $this->assertSame('never_run', data_get($payload, 'retention.scopes.reports_backups.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retention.scopes.content_releases_retention.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retention.scopes.legacy_private_private_cleanup.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_gc.status'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_gc.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_offload.status'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_offload.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'exact_authority.latest_backfill.status'));
        $this->assertSame('never_run', data_get($payload, 'exact_authority.latest_backfill.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'rehydrate.status'));
        $this->assertSame('never_run', data_get($payload, 'rehydrate.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'quarantine.status'));
        $this->assertSame('never_run', data_get($payload, 'quarantine.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'restore.status'));
        $this->assertSame('never_run', data_get($payload, 'restore.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'purge.status'));
        $this->assertSame('never_run', data_get($payload, 'purge.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.quarantine.status'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.quarantine.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.purge.status'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.purge.freshness_state'));
        $this->assertSame('unknown_freshness', data_get($payload, 'runtime_truth.freshness_state'));
        $this->assertSame('unknown_freshness', data_get($payload, 'automation_readiness.freshness_state'));
        $this->assertSame('degraded', data_get($payload, 'attention_digest.overall_state'));
        $this->assertSame(['inventory'], data_get($payload, 'attention_digest.not_available_sections'));
        $this->assertContains('rehydrate', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertContains('retention.scopes.reports_backups', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertSame([], data_get($payload, 'attention_digest.stale_sections'));
        $this->assertSame(1, data_get($payload, 'attention_digest.counts.not_available'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'attention_digest.counts.never_run'));
        $this->assertSame('inventory is not available', data_get($payload, 'attention_digest.attention_items.0.message'));
    }

    private function seedControlPlaneTruth(): void
    {
        $now = now();

        DB::table('storage_blobs')->insert([
            'hash' => str_repeat('a', 64),
            'disk' => 'local',
            'storage_path' => 'app/private/blobs/aa/blob-a',
            'size_bytes' => 128,
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 1,
            'first_seen_at' => $now,
            'last_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('storage_blob_locations')->insert([
            'blob_hash' => str_repeat('a', 64),
            'disk' => 's3',
            'storage_path' => 'rollout/blobs/aa/blob-a',
            'location_kind' => 'remote_copy',
            'size_bytes' => 128,
            'checksum' => 'sha256:'.str_repeat('a', 64),
            'etag' => 'etag-a',
            'storage_class' => 'STANDARD',
            'verified_at' => $now,
            'meta_json' => json_encode(['copied' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $manifestId = DB::table('content_release_exact_manifests')->insertGetId([
            'content_pack_release_id' => null,
            'source_identity_hash' => str_repeat('b', 64),
            'manifest_hash' => str_repeat('c', 64),
            'schema_version' => 'storage_exact_manifest.v1',
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => storage_path('app/private/content_releases/release-1/source_pack'),
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('d', 64),
            'content_hash' => str_repeat('e', 64),
            'norms_version' => '2026Q1',
            'source_commit' => 'git-seeded',
            'file_count' => 1,
            'total_size_bytes' => 128,
            'payload_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sealed_at' => $now,
            'last_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('content_release_exact_manifest_files')->insert([
            'content_release_exact_manifest_id' => $manifestId,
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => str_repeat('a', 64),
            'size_bytes' => 128,
            'role' => 'manifest',
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'checksum' => 'sha256:'.str_repeat('c', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->writeJson(storage_path('app/private/prune_plans/20260321_reports_backups_seed.json'), [
            'schema' => 'storage_prune_plan.v2',
            'scope' => 'reports_backups',
            'strategy' => 'strict',
            'generated_at' => $now->copy()->subMinutes(30)->toIso8601String(),
            'summary' => ['files' => 2, 'bytes' => 512],
        ]);
        $this->writeJson(storage_path('app/private/prune_plans/20260321_content_releases_retention_seed.json'), [
            'schema' => 'storage_prune_plan.v2',
            'scope' => 'content_releases_retention',
            'strategy' => 'strict',
            'generated_at' => $now->copy()->subMinutes(25)->toIso8601String(),
            'summary' => ['files' => 3, 'bytes' => 1024],
        ]);
        $this->writeJson(storage_path('app/private/prune_plans/20260321_legacy_private_private_cleanup_seed.json'), [
            'schema' => 'storage_prune_plan.v2',
            'scope' => 'legacy_private_private_cleanup',
            'strategy' => 'strict',
            'generated_at' => $now->copy()->subMinutes(20)->toIso8601String(),
            'summary' => ['files' => 1, 'bytes' => 256],
        ]);

        $this->writeJson(storage_path('app/private/quarantine/release_roots/run-1/items/item-1/root/.quarantine.json'), [
            'source_kind' => 'legacy.source_pack',
        ]);
        $this->writeJson(storage_path('app/private/quarantine/restore_runs/restore-1/run.json'), [
            'schema' => 'storage_restore_quarantined_root_run.v1',
            'status' => 'success',
        ]);
        $this->writeJson(storage_path('app/private/quarantine/purge_runs/purge-1/items/item-1/purge.json'), [
            'schema' => 'storage_purge_quarantined_root_run.v1',
            'status' => 'success',
        ]);
        $this->writeJson(storage_path('app/private/retirement_runs/retire-1/run.json'), [
            'schema' => 'storage_retire_exact_roots_run.v1',
            'status' => 'success',
        ]);
        $this->writeJson(storage_path('app/private/rehydrate_runs/rehydrate-1/run.json'), [
            'schema' => 'storage_rehydrate_exact_release_run.v1',
            'status' => 'success',
        ]);

        $this->insertAudit('storage_inventory', 'inventory', [
            'schema_version' => 2,
            'generated_at' => $now->copy()->subHours(3)->toIso8601String(),
            'focus_scopes' => ['reports', 'artifacts'],
            'area_count' => 2,
            'totals' => ['files' => 5, 'bytes' => 2048],
            'duplicate_summary' => ['file_refs' => 1],
        ], 'success', 'scheduled_or_manual', $now->copy()->subHours(3));

        $this->insertAudit('storage_prune', 'reports_backups', [
            'scope' => 'reports_backups',
            'strategy' => 'strict',
            'deleted_files_count' => 2,
            'deleted_bytes' => 512,
            'missing_files' => 0,
            'skipped_files' => 0,
            'plan' => storage_path('app/private/prune_plans/20260321_reports_backups_seed.json'),
        ], 'success', 'retention_execute', $now->copy()->subHours(2));
        $this->insertAudit('storage_prune', 'content_releases_retention', [
            'scope' => 'content_releases_retention',
            'strategy' => 'strict',
            'deleted_files_count' => 0,
            'deleted_bytes' => 0,
            'missing_files' => 0,
            'skipped_files' => 1,
            'plan' => storage_path('app/private/prune_plans/20260321_content_releases_retention_seed.json'),
        ], 'success', 'retention_execute', $now->copy()->subHours(2)->addMinutes(5));
        $this->insertAudit('storage_prune', 'legacy_private_private_cleanup', [
            'scope' => 'legacy_private_private_cleanup',
            'strategy' => 'strict',
            'deleted_files_count' => 0,
            'deleted_bytes' => 0,
            'missing_files' => 0,
            'skipped_files' => 0,
            'plan' => storage_path('app/private/prune_plans/20260321_legacy_private_private_cleanup_seed.json'),
        ], 'success', 'retention_execute', $now->copy()->subHours(2)->addMinutes(10));

        $this->insertAudit('storage_blob_gc', 'blob_gc', [
            'schema' => 'storage_blob_gc_plan.v1',
            'plan' => storage_path('app/private/gc_plans/gc-plan.json'),
            'reachable_blob_count' => 1,
            'unreachable_blob_count' => 0,
            'planned_deletion_count' => 0,
            'dry_run_only' => true,
        ], 'success', 'reachability_plan', $now->copy()->subHours(2));

        $this->insertAudit('storage_blob_offload', 'blob_offload', [
            'schema' => 'storage_blob_offload_plan.v1',
            'mode' => 'executed',
            'disk' => 's3',
            'plan' => storage_path('app/private/offload_plans/offload-plan.json'),
            'candidate_count' => 1,
            'skipped_count' => 0,
            'bytes' => 128,
            'uploaded_count' => 1,
            'verified_count' => 1,
            'failed_count' => 0,
            'warnings' => [],
            'copy_only' => true,
        ], 'success', 'blob_offload_copy_only', $now->copy()->subHours(2));

        $this->insertAudit('storage_backfill_exact_release_file_sets', 'exact_release_file_sets', [
            'scanned_roots' => 1,
            'created_manifests' => 1,
            'updated_manifests' => 0,
        ], 'success', 'exact_release_file_set_backfill', $now->copy()->subHours(2));

        $this->insertAudit('storage_rehydrate_exact_release', 'manifest-1', [
            'mode' => 'executed',
            'disk' => 's3',
            'plan_path' => storage_path('app/private/rehydrate_plans/rehydrate-plan.json'),
            'plan' => ['target_root' => storage_path('app/private/rehydrate_runs/rehydrate-1/root')],
            'result' => [
                'status' => 'success',
                'run_dir' => storage_path('app/private/rehydrate_runs/rehydrate-1'),
                'verified_files' => 1,
                'verified_bytes' => 128,
            ],
        ], 'success', 'exact_release_rehydrate_verify', $now->copy()->subMinutes(90));

        $this->insertAudit('storage_quarantine_exact_roots', 'exact_roots', [
            'mode' => 'executed',
            'plan_path' => storage_path('app/private/quarantine_plans/quarantine-plan.json'),
            'plan' => ['summary' => ['candidate_count' => 1, 'blocked_count' => 0, 'skipped_count' => 0]],
            'result' => [
                'status' => 'success',
                'run_dir' => storage_path('app/private/quarantine/release_roots/run-1'),
                'quarantined' => [['target_root' => storage_path('app/private/quarantine/release_roots/run-1/items/item-1/root')]],
            ],
        ], 'success', 'exact_root_quarantine', $now->copy()->subMinutes(80));

        $this->insertAudit('storage_restore_quarantined_root', 'quarantined_root', [
            'mode' => 'executed',
            'plan_path' => storage_path('app/private/restore_plans/restore-plan.json'),
            'plan' => ['target_root' => storage_path('app/private/content_releases/release-1/source_pack')],
            'result' => [
                'status' => 'success',
                'run_dir' => storage_path('app/private/quarantine/restore_runs/restore-1'),
                'restored_root' => storage_path('app/private/content_releases/release-1/source_pack'),
                'target_root' => storage_path('app/private/content_releases/release-1/source_pack'),
            ],
        ], 'success', 'quarantined_root_restore', $now->copy()->subMinutes(70));

        $this->insertAudit('storage_purge_quarantined_root', 'quarantined_root', [
            'mode' => 'executed',
            'plan_path' => storage_path('app/private/purge_plans/purge-plan.json'),
            'plan' => ['blocked_reason' => null],
            'result' => [
                'status' => 'success',
                'run_dir' => storage_path('app/private/quarantine/purge_runs/purge-1'),
                'receipt_path' => storage_path('app/private/quarantine/purge_runs/purge-1/items/item-1/purge.json'),
            ],
        ], 'success', 'quarantined_root_purge', $now->copy()->subMinutes(60));

        $this->insertAudit('storage_retire_exact_roots', 'exact_roots', [
            'mode' => 'executed',
            'plan_path' => storage_path('app/private/retirement_plans/retire-quarantine-plan.json'),
            'plan' => ['action' => 'quarantine'],
            'result' => [
                'status' => 'success',
                'run_dir' => storage_path('app/private/retirement_runs/retire-1'),
                'success_count' => 1,
                'failure_count' => 0,
                'blocked_count' => 0,
                'skipped_count' => 0,
            ],
        ], 'success', 'exact_root_retirement', $now->copy()->subMinutes(50));

        $this->insertAudit('storage_retire_exact_roots', 'exact_roots', [
            'mode' => 'planned',
            'plan_path' => storage_path('app/private/retirement_plans/retire-purge-plan.json'),
            'plan' => ['action' => 'purge'],
            'result' => [
                'status' => 'planned',
                'run_dir' => storage_path('app/private/retirement_runs/retire-1'),
                'success_count' => 0,
                'failure_count' => 0,
                'blocked_count' => 1,
                'skipped_count' => 0,
            ],
        ], 'success', 'exact_root_retirement', $now->copy()->subMinutes(40));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function insertAudit(string $action, string $targetId, array $meta, string $result, string $reason, \Illuminate\Support\Carbon $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => 'storage',
            'target_id' => $targetId,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_status',
            'request_id' => null,
            'reason' => $reason,
            'result' => $result,
            'created_at' => $createdAt,
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
