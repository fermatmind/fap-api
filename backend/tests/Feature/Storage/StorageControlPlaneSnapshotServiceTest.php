<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\StorageControlPlaneSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageControlPlaneSnapshotServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-snapshot-service-'.Str::uuid();
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

    public function test_service_creates_snapshot_file_and_audit_without_other_mutation(): void
    {
        $this->seedMinimalTruth();
        $lifecycleFiles = $this->seedReportsArtifactsLifecycleTruth();
        $archiveTruth = $this->seedReportArtifactsArchiveTruth();
        $rehydrateTruth = $this->seedReportArtifactsRehydrateTruth();
        $shrinkTruth = $this->seedReportArtifactsShrinkTruth();
        $bucket = [
            '.materialization.json' => json_encode([
                'storage_path' => 'private/packs_v2/BIG5_OCEAN/v1/release-a',
                'manifest_hash' => str_repeat('b', 64),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'compiled/manifest.json' => str_repeat('m', 16),
            'compiled/questions.compiled.json' => str_repeat('q', 6),
        ];
        $this->seedMaterializedBucket('BIG5_OCEAN', 'v1', str_repeat('a', 64), str_repeat('b', 64), $bucket);

        $auditCountBefore = DB::table('audit_logs')->count();
        $filesBefore = $this->storageFilesSnapshot();

        $payload = app(StorageControlPlaneSnapshotService::class)->createSnapshot();

        $this->assertSame('storage_control_plane_snapshot.v1', $payload['snapshot_schema']);
        $this->assertSame('snapshotted', $payload['status']);
        $this->assertIsString($payload['snapshot_path']);
        $this->assertFileExists($payload['snapshot_path']);
        $this->assertArrayHasKey('inventory', $payload);
        $this->assertArrayHasKey('retention', $payload);
        $this->assertArrayHasKey('blob_coverage', $payload);
        $this->assertArrayHasKey('exact_authority', $payload);
        $this->assertArrayHasKey('rehydrate', $payload);
        $this->assertArrayHasKey('quarantine', $payload);
        $this->assertArrayHasKey('restore', $payload);
        $this->assertArrayHasKey('purge', $payload);
        $this->assertArrayHasKey('retirement', $payload);
        $this->assertArrayHasKey('report_artifacts_archive', $payload);
        $this->assertArrayHasKey('report_artifacts_posture', $payload);
        $this->assertArrayHasKey('reports_artifacts_lifecycle', $payload);
        $this->assertArrayHasKey('materialized_cache', $payload);
        $this->assertArrayHasKey('cost_reclaim_posture', $payload);
        $this->assertArrayHasKey('runtime_truth', $payload);
        $this->assertArrayHasKey('automation_readiness', $payload);
        $this->assertArrayHasKey('attention_digest', $payload);
        $this->assertSame('ok', data_get($payload, 'inventory.status'));
        $this->assertArrayHasKey('last_updated_at', $payload['inventory']);
        $this->assertArrayHasKey('freshness_age_seconds', $payload['inventory']);
        $this->assertArrayHasKey('freshness_state', $payload['inventory']);
        $this->assertArrayHasKey('freshness_source_type', $payload['inventory']);
        $this->assertSame('fresh', data_get($payload, 'inventory.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'rehydrate.status'));
        $this->assertSame('never_run', data_get($payload, 'rehydrate.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retention.status'));
        $this->assertSame('never_run', data_get($payload, 'retention.scopes.reports_backups.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'restore.status'));
        $this->assertSame('never_run', data_get($payload, 'restore.freshness_state'));
        $this->assertSame('ok', data_get($payload, 'reports_artifacts_lifecycle.status'));
        $this->assertSame(storage_path('app/private/artifacts'), data_get($payload, 'reports_artifacts_lifecycle.canonical_root_path'));
        $this->assertSame(storage_path('app/private/reports'), data_get($payload, 'reports_artifacts_lifecycle.legacy_root_path'));
        $this->assertSame(1, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.report_json_files'));
        $this->assertSame(2, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.pdf_files'));
        $this->assertSame($lifecycleFiles['canonical_bytes'], data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.bytes'));
        $this->assertSame(1, data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.report_json_files'));
        $this->assertSame(2, data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.pdf_files'));
        $this->assertSame($lifecycleFiles['legacy_bytes'], data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.bytes'));
        $this->assertSame(1, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.timestamp_backup_json_files'));
        $this->assertSame('none_proven', data_get($payload, 'reports_artifacts_lifecycle.archive_candidate_status'));
        $this->assertSame('fresh', data_get($payload, 'reports_artifacts_lifecycle.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'reports_artifacts_lifecycle.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_archive.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_archive.durable_receipt_source'));
        $this->assertSame('s3', data_get($payload, 'report_artifacts_archive.target_disk'));
        $this->assertSame('execute', data_get($payload, 'report_artifacts_archive.latest_mode'));
        $this->assertSame($archiveTruth['plan_path'], data_get($payload, 'report_artifacts_archive.latest_plan_path'));
        $this->assertSame($archiveTruth['run_path'], data_get($payload, 'report_artifacts_archive.latest_run_path'));
        $this->assertTrue((bool) data_get($payload, 'report_artifacts_archive.latest_run_path_exists'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_archive.latest_summary.candidate_count'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_archive.latest_summary.results_count'));
        $this->assertSame('fresh', data_get($payload, 'report_artifacts_archive.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_archive.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_posture.durable_receipt_source'));
        $this->assertSame('s3', data_get($payload, 'report_artifacts_posture.target_disk'));
        $this->assertSame($archiveTruth['plan_path'], data_get($payload, 'report_artifacts_posture.archive.latest_plan_path'));
        $this->assertSame($archiveTruth['run_path'], data_get($payload, 'report_artifacts_posture.archive.latest_run_path'));
        $this->assertTrue((bool) data_get($payload, 'report_artifacts_posture.archive.latest_run_path_exists'));
        $this->assertSame($rehydrateTruth['plan_path'], data_get($payload, 'report_artifacts_posture.rehydrate.latest_plan_path'));
        $this->assertFalse((bool) data_get($payload, 'report_artifacts_posture.rehydrate.latest_run_path_exists'));
        $this->assertSame(2, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.skipped_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.blocked_count'));
        $this->assertSame($shrinkTruth['plan_path'], data_get($payload, 'report_artifacts_posture.shrink.latest_plan_path'));
        $this->assertFalse((bool) data_get($payload, 'report_artifacts_posture.shrink.latest_run_path_exists'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_missing_remote_count'));
        $this->assertSame(2, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_missing_archive_proof_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_hash_mismatch_count'));
        $this->assertSame('fresh', data_get($payload, 'report_artifacts_posture.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_posture.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'materialized_cache.status'));
        $this->assertSame(storage_path('app/private/packs_v2_materialized'), data_get($payload, 'materialized_cache.root_path'));
        $this->assertSame(1, data_get($payload, 'materialized_cache.bucket_count'));
        $this->assertSame(3, data_get($payload, 'materialized_cache.total_files'));
        $this->assertSame($this->totalBytesForFiles($bucket), data_get($payload, 'materialized_cache.total_bytes'));
        $this->assertSame([
            str_replace('\\', '/', storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64))),
        ], data_get($payload, 'materialized_cache.sample_bucket_paths'));
        $this->assertSame('storage_path + manifest_hash', data_get($payload, 'materialized_cache.cache_key_contract'));
        $this->assertSame('derived_cache_return_surface', data_get($payload, 'materialized_cache.runtime_role'));
        $this->assertFalse((bool) data_get($payload, 'materialized_cache.source_of_truth'));
        $this->assertFalse((bool) data_get($payload, 'materialized_cache.zero_state'));
        $this->assertNull(data_get($payload, 'materialized_cache.last_updated_at'));
        $this->assertNull(data_get($payload, 'materialized_cache.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'materialized_cache.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'materialized_cache.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'cost_reclaim_posture.status'));
        $this->assertSame('storage_cost_analyzer.v1', data_get($payload, 'cost_reclaim_posture.source_schema_version'));
        $this->assertSame(storage_path(), data_get($payload, 'cost_reclaim_posture.root_path'));
        $this->assertSame($this->totalBytesForFiles($bucket), data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.bytes'));
        $this->assertSame(3, data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.file_count'));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.last_updated_at'));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'cost_reclaim_posture.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'cost_reclaim_posture.freshness_source_type'));
        $this->assertSame([
            'category' => 'v2_materialized_cache',
            'bytes' => $this->totalBytesForFiles($bucket),
            'risk_level' => 'medium',
        ], $this->reclaimCategory($payload, 'v2_materialized_cache'));
        $this->assertSame('unknown_freshness', data_get($payload, 'runtime_truth.freshness_state'));
        $this->assertSame('unknown_freshness', data_get($payload, 'automation_readiness.freshness_state'));
        $this->assertSame('attention_required', data_get($payload, 'attention_digest.overall_state'));
        $this->assertContains('rehydrate', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertContains('retention.scopes.reports_backups', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertSame([], data_get($payload, 'attention_digest.not_available_sections'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_control_plane_snapshot')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($auditCountBefore + 1, DB::table('audit_logs')->count());
        $this->assertSame('success', $audit->result);
        $this->assertSame('control_plane_snapshot', $audit->reason);

        $auditMeta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($auditMeta);
        $this->assertSame($payload['snapshot_path'], $auditMeta['snapshot_path'] ?? null);

        $storedPayload = json_decode((string) File::get($payload['snapshot_path']), true);
        $this->assertSame($payload, $storedPayload);

        $filesAfter = $this->storageFilesSnapshot();
        $newFiles = array_values(array_diff($filesAfter, $filesBefore));
        $this->assertCount(1, $newFiles);
        $this->assertStringStartsWith('app/private/control_plane_snapshots/', $newFiles[0]);
        $this->assertSame([], glob($this->isolatedStoragePath.'/app/private/blobs/**/*'));
        $this->assertSame([], $this->existingFilesUnder('app/private/prune_plans'));
        $this->assertSame([], $this->existingFilesUnder('app/private/rehydrate_runs'));
        $this->assertSame([], $this->existingFilesUnder('app/private/quarantine'));
        $this->assertSame([], $this->existingFilesUnder('app/private/retirement_runs'));
    }

    public function test_service_preserves_explicit_missing_truth_markers(): void
    {
        $payload = app(StorageControlPlaneSnapshotService::class)->createSnapshot();

        $this->assertSame('not_available', data_get($payload, 'inventory.status'));
        $this->assertSame('not_available', data_get($payload, 'inventory.freshness_state'));
        $this->assertSame('not_available', data_get($payload, 'reports_artifacts_lifecycle.status'));
        $this->assertSame(storage_path('app/private/artifacts'), data_get($payload, 'reports_artifacts_lifecycle.canonical_root_path'));
        $this->assertSame(storage_path('app/private/reports'), data_get($payload, 'reports_artifacts_lifecycle.legacy_root_path'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.report_json_files'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.pdf_files'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.bytes'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.report_json_files'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.pdf_files'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.legacy_fallback_live.bytes'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.timestamp_backup_json_files'));
        $this->assertSame('none_proven', data_get($payload, 'reports_artifacts_lifecycle.archive_candidate_status'));
        $this->assertNull(data_get($payload, 'reports_artifacts_lifecycle.last_updated_at'));
        $this->assertNull(data_get($payload, 'reports_artifacts_lifecycle.freshness_age_seconds'));
        $this->assertSame('not_available', data_get($payload, 'reports_artifacts_lifecycle.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'reports_artifacts_lifecycle.freshness_source_type'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_archive.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_archive.durable_receipt_source'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.latest_plan_path'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.latest_run_path'));
        $this->assertFalse((bool) data_get($payload, 'report_artifacts_archive.latest_run_path_exists'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_archive.latest_summary.candidate_count'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_archive.latest_summary.results_count'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.last_updated_at'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.freshness_age_seconds'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_archive.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_archive.freshness_source_type'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_posture.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_posture.durable_receipt_source'));
        $this->assertNull(data_get($payload, 'report_artifacts_posture.target_disk'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_posture.archive.status'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_posture.rehydrate.status'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_posture.shrink.status'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_posture.archive.latest_summary.candidate_count'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.blocked_count'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_missing_archive_proof_count'));
        $this->assertNull(data_get($payload, 'report_artifacts_posture.last_updated_at'));
        $this->assertNull(data_get($payload, 'report_artifacts_posture.freshness_age_seconds'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_posture.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_posture.freshness_source_type'));
        $this->assertSame('never_run', data_get($payload, 'retention.status'));
        $this->assertSame('never_run', data_get($payload, 'retention.scopes.reports_backups.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_gc.status'));
        $this->assertSame('never_run', data_get($payload, 'blob_coverage.blob_gc.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'quarantine.status'));
        $this->assertSame('never_run', data_get($payload, 'quarantine.freshness_state'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.quarantine.status'));
        $this->assertSame('never_run', data_get($payload, 'retirement.actions.quarantine.freshness_state'));
        $this->assertSame('ok', data_get($payload, 'materialized_cache.status'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.bucket_count'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.total_files'));
        $this->assertSame(0, data_get($payload, 'materialized_cache.total_bytes'));
        $this->assertSame([], data_get($payload, 'materialized_cache.sample_bucket_paths'));
        $this->assertFalse((bool) data_get($payload, 'materialized_cache.source_of_truth'));
        $this->assertTrue((bool) data_get($payload, 'materialized_cache.zero_state'));
        $this->assertNull(data_get($payload, 'materialized_cache.last_updated_at'));
        $this->assertNull(data_get($payload, 'materialized_cache.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'materialized_cache.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'materialized_cache.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'cost_reclaim_posture.status'));
        $this->assertSame('storage_cost_analyzer.v1', data_get($payload, 'cost_reclaim_posture.source_schema_version'));
        $this->assertSame(storage_path(), data_get($payload, 'cost_reclaim_posture.root_path'));
        $this->assertSame(0, data_get($payload, 'cost_reclaim_posture.summary.total_bytes'));
        $this->assertSame(0, data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.bytes'));
        $this->assertSame(0, data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.file_count'));
        $this->assertContains('runtime_or_data_truth', data_get($payload, 'cost_reclaim_posture.no_touch_categories', []));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.last_updated_at'));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'cost_reclaim_posture.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'cost_reclaim_posture.freshness_source_type'));
        $this->assertNull($this->reclaimCategory($payload, 'v2_materialized_cache'));
        $this->assertSame('degraded', data_get($payload, 'attention_digest.overall_state'));
        $this->assertSame([
            'inventory',
            'report_artifacts_posture.archive',
            'report_artifacts_posture.rehydrate',
            'report_artifacts_posture.shrink',
            'reports_artifacts_lifecycle',
        ], data_get($payload, 'attention_digest.not_available_sections'));
        $this->assertContains('quarantine', data_get($payload, 'attention_digest.never_run_sections', []));
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
            'user_agent' => 'test/storage_control_plane_snapshot_service',
            'request_id' => null,
            'reason' => 'seed',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{plan_path:string,run_path:string}
     */
    private function seedReportArtifactsArchiveTruth(bool $runPathExists = true): array
    {
        $planPath = storage_path('app/private/report_artifact_archive_plans/archive-plan.json');
        $runPath = storage_path('app/private/report_artifact_archive_runs/archive-run/run.json');
        $this->writeRaw($planPath, "{\"schema\":\"storage_archive_report_artifacts_plan.v1\"}\n");

        if ($runPathExists) {
            $this->writeRaw($runPath, "{\"schema\":\"storage_archive_report_artifacts_run.v1\"}\n");
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_archive_report_artifacts',
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_archive',
            'meta_json' => json_encode([
                'schema' => 'storage_archive_report_artifacts_run.v1',
                'mode' => 'execute',
                'target_disk' => 's3',
                'plan' => $planPath,
                'plan_path' => $planPath,
                'run_path' => $runPath,
                'candidate_count' => 3,
                'copied_count' => 2,
                'verified_count' => 3,
                'already_archived_count' => 1,
                'failed_count' => 0,
                'results_count' => 3,
                'durable_receipt_source' => 'audit_logs.meta_json',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_snapshot_service',
            'request_id' => null,
            'reason' => 'manual_archive_copy',
            'result' => 'success',
            'created_at' => now()->subMinutes(10),
        ]);

        return [
            'plan_path' => $planPath,
            'run_path' => $runPath,
        ];
    }

    /**
     * @return array{plan_path:string}
     */
    private function seedReportArtifactsRehydrateTruth(): array
    {
        $planPath = storage_path('app/private/report_artifact_rehydrate_plans/rehydrate-plan.json');
        $this->writeRaw($planPath, "{\"schema\":\"storage_rehydrate_report_artifacts_plan.v1\"}\n");

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_rehydrate_report_artifacts',
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_rehydrate',
            'meta_json' => json_encode([
                'schema' => 'storage_rehydrate_report_artifacts_plan.v1',
                'mode' => 'dry_run',
                'target_disk' => 's3',
                'plan' => $planPath,
                'plan_path' => $planPath,
                'candidate_count' => 3,
                'rehydrated_count' => 0,
                'verified_count' => 0,
                'skipped_count' => 2,
                'blocked_count' => 1,
                'failed_count' => 0,
                'results_count' => 0,
                'durable_receipt_source' => 'audit_logs.meta_json',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_snapshot_service',
            'request_id' => null,
            'reason' => 'manual_archive_rehydrate',
            'result' => 'success',
            'created_at' => now()->subMinutes(9),
        ]);

        return ['plan_path' => $planPath];
    }

    /**
     * @return array{plan_path:string}
     */
    private function seedReportArtifactsShrinkTruth(): array
    {
        $planPath = storage_path('app/private/report_artifact_shrink_plans/shrink-plan.json');
        $this->writeRaw($planPath, "{\"schema\":\"storage_shrink_archived_report_artifacts_plan.v1\"}\n");

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_shrink_archived_report_artifacts',
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_shrink',
            'meta_json' => json_encode([
                'schema' => 'storage_shrink_archived_report_artifacts_plan.v1',
                'mode' => 'dry_run',
                'target_disk' => 's3',
                'plan' => $planPath,
                'plan_path' => $planPath,
                'candidate_count' => 3,
                'deleted_count' => 0,
                'skipped_missing_local_count' => 0,
                'blocked_missing_remote_count' => 1,
                'blocked_missing_archive_proof_count' => 2,
                'blocked_missing_rehydrate_proof_count' => 0,
                'blocked_hash_mismatch_count' => 1,
                'failed_count' => 0,
                'results_count' => 0,
                'durable_receipt_source' => 'audit_logs.meta_json',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test/storage_control_plane_snapshot_service',
            'request_id' => null,
            'reason' => 'manual_archive_backed_shrink',
            'result' => 'success',
            'created_at' => now()->subMinutes(8),
        ]);

        return ['plan_path' => $planPath];
    }

    private function writeRaw(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
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
     * @return array{canonical_bytes:int,legacy_bytes:int}
     */
    private function seedReportsArtifactsLifecycleTruth(): array
    {
        $canonicalFiles = [
            'app/private/artifacts/reports/MBTI/attempt-canonical/report.json' => '{"canonical":true}',
            'app/private/artifacts/pdf/MBTI/attempt-canonical/nohash/report_free.pdf' => '%PDF-free',
            'app/private/artifacts/pdf/MBTI/attempt-canonical/nohash/report_full.pdf' => '%PDF-full',
        ];
        $legacyFiles = [
            'app/private/reports/attempt-legacy/report.json' => '{"legacy":true}',
            'app/private/reports/attempt-legacy/report_free.pdf' => '%PDF-legacy-free',
            'app/private/reports/attempt-legacy/report_full.pdf' => '%PDF-legacy-full',
            'app/private/reports/attempt-legacy/report.20260321_010101.json' => '{"backup":true}',
        ];

        foreach ($canonicalFiles as $relativePath => $contents) {
            $this->writeRaw(storage_path($relativePath), $contents);
        }

        foreach ($legacyFiles as $relativePath => $contents) {
            $this->writeRaw(storage_path($relativePath), $contents);
        }

        return [
            'canonical_bytes' => strlen($canonicalFiles['app/private/artifacts/reports/MBTI/attempt-canonical/report.json'])
                + strlen($canonicalFiles['app/private/artifacts/pdf/MBTI/attempt-canonical/nohash/report_free.pdf'])
                + strlen($canonicalFiles['app/private/artifacts/pdf/MBTI/attempt-canonical/nohash/report_full.pdf']),
            'legacy_bytes' => strlen($legacyFiles['app/private/reports/attempt-legacy/report.json'])
                + strlen($legacyFiles['app/private/reports/attempt-legacy/report_free.pdf'])
                + strlen($legacyFiles['app/private/reports/attempt-legacy/report_full.pdf']),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reclaimCategory(array $payload, string $category): ?array
    {
        foreach ((array) data_get($payload, 'cost_reclaim_posture.reclaim_categories', []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if ((string) ($candidate['category'] ?? '') === $category) {
                return $candidate;
            }
        }

        return null;
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
