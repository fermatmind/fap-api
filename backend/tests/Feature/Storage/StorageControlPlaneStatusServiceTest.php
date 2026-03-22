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
        $lifecycleFiles = $this->seedReportsArtifactsLifecycleTruth();
        $archiveTruth = $this->seedReportArtifactsArchiveTruth();
        $rehydrateTruth = $this->seedReportArtifactsRehydrateTruth();
        $shrinkTruth = $this->seedReportArtifactsShrinkTruth();
        $bucketOne = [
            '.materialization.json' => json_encode([
                'storage_path' => 'private/packs_v2/BIG5_OCEAN/v1/release-a',
                'manifest_hash' => str_repeat('b', 64),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'compiled/manifest.json' => str_repeat('m', 40),
            'compiled/questions.compiled.json' => str_repeat('q', 20),
        ];
        $bucketTwo = [
            '.materialization.json' => json_encode([
                'storage_path' => 'private/packs_v2/EQ60/v2/release-b',
                'manifest_hash' => str_repeat('d', 64),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'compiled/manifest.json' => str_repeat('n', 30),
            'compiled/layout.compiled.json' => str_repeat('l', 10),
        ];
        $this->seedMaterializedBucket('BIG5_OCEAN', 'v1', str_repeat('a', 64), str_repeat('b', 64), $bucketOne);
        $this->seedMaterializedBucket('EQ60', 'v2', str_repeat('c', 64), str_repeat('d', 64), $bucketTwo);
        $expectedBytes = $this->totalBytesForFiles($bucketOne) + $this->totalBytesForFiles($bucketTwo);

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
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.latest_reports_backups_policy.keep_days'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.latest_reports_backups_policy.keep_timestamp_backups'));
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
        $this->assertSame(2, data_get($payload, 'report_artifacts_archive.latest_summary.copied_count'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_archive.latest_summary.verified_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_archive.latest_summary.already_archived_count'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_archive.latest_summary.failed_count'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_archive.latest_summary.results_count'));
        $this->assertSame('fresh', data_get($payload, 'report_artifacts_archive.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_archive.freshness_source_type'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_posture.durable_receipt_source'));
        $this->assertSame('s3', data_get($payload, 'report_artifacts_posture.target_disk'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.archive.status'));
        $this->assertSame($archiveTruth['plan_path'], data_get($payload, 'report_artifacts_posture.archive.latest_plan_path'));
        $this->assertSame($archiveTruth['run_path'], data_get($payload, 'report_artifacts_posture.archive.latest_run_path'));
        $this->assertTrue((bool) data_get($payload, 'report_artifacts_posture.archive.latest_run_path_exists'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_posture.archive.latest_summary.candidate_count'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.rehydrate.status'));
        $this->assertSame('dry_run', data_get($payload, 'report_artifacts_posture.rehydrate.latest_mode'));
        $this->assertSame($rehydrateTruth['plan_path'], data_get($payload, 'report_artifacts_posture.rehydrate.latest_plan_path'));
        $this->assertNull(data_get($payload, 'report_artifacts_posture.rehydrate.latest_run_path'));
        $this->assertFalse((bool) data_get($payload, 'report_artifacts_posture.rehydrate.latest_run_path_exists'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.candidate_count'));
        $this->assertSame(2, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.skipped_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.blocked_count'));
        $this->assertSame(0, data_get($payload, 'report_artifacts_posture.rehydrate.latest_summary.results_count'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.shrink.status'));
        $this->assertSame('dry_run', data_get($payload, 'report_artifacts_posture.shrink.latest_mode'));
        $this->assertSame($shrinkTruth['plan_path'], data_get($payload, 'report_artifacts_posture.shrink.latest_plan_path'));
        $this->assertNull(data_get($payload, 'report_artifacts_posture.shrink.latest_run_path'));
        $this->assertFalse((bool) data_get($payload, 'report_artifacts_posture.shrink.latest_run_path_exists'));
        $this->assertSame(3, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.candidate_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_missing_remote_count'));
        $this->assertSame(2, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_missing_archive_proof_count'));
        $this->assertSame(1, data_get($payload, 'report_artifacts_posture.shrink.latest_summary.blocked_hash_mismatch_count'));
        $this->assertSame('fresh', data_get($payload, 'report_artifacts_posture.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'report_artifacts_posture.freshness_source_type'));
        $this->assertIsString(data_get($payload, 'report_artifacts_posture.last_updated_at'));
        $this->assertIsInt(data_get($payload, 'report_artifacts_posture.freshness_age_seconds'));
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
        $this->assertSame('ok', data_get($payload, 'materialized_cache.status'));
        $this->assertSame(storage_path('app/private/packs_v2_materialized'), data_get($payload, 'materialized_cache.root_path'));
        $this->assertSame(2, data_get($payload, 'materialized_cache.bucket_count'));
        $this->assertSame(6, data_get($payload, 'materialized_cache.total_files'));
        $this->assertSame($expectedBytes, data_get($payload, 'materialized_cache.total_bytes'));
        $this->assertSame([
            str_replace('\\', '/', storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.str_repeat('a', 64).'/'.str_repeat('b', 64))),
            str_replace('\\', '/', storage_path('app/private/packs_v2_materialized/EQ60/v2/'.str_repeat('c', 64).'/'.str_repeat('d', 64))),
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
        $this->assertSame($expectedBytes, data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.bytes'));
        $this->assertSame(6, data_get($payload, 'cost_reclaim_posture.by_category.v2_materialized_cache.file_count'));
        $this->assertContains('runtime_or_data_truth', data_get($payload, 'cost_reclaim_posture.no_touch_categories', []));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.last_updated_at'));
        $this->assertNull(data_get($payload, 'cost_reclaim_posture.freshness_age_seconds'));
        $this->assertSame('unknown_freshness', data_get($payload, 'cost_reclaim_posture.freshness_state'));
        $this->assertSame('disk-derived', data_get($payload, 'cost_reclaim_posture.freshness_source_type'));
        $this->assertSame([
            'category' => 'v2_materialized_cache',
            'bytes' => $expectedBytes,
            'risk_level' => 'medium',
        ], $this->reclaimCategory($payload, 'v2_materialized_cache'));
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
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.latest_reports_backups_policy.keep_days'));
        $this->assertSame(0, data_get($payload, 'reports_artifacts_lifecycle.safe_to_prune_derived.latest_reports_backups_policy.keep_timestamp_backups'));
        $this->assertSame('none_proven', data_get($payload, 'reports_artifacts_lifecycle.archive_candidate_status'));
        $this->assertNull(data_get($payload, 'reports_artifacts_lifecycle.last_updated_at'));
        $this->assertNull(data_get($payload, 'reports_artifacts_lifecycle.freshness_age_seconds'));
        $this->assertSame('not_available', data_get($payload, 'reports_artifacts_lifecycle.freshness_state'));
        $this->assertSame('audit-derived', data_get($payload, 'reports_artifacts_lifecycle.freshness_source_type'));
        $this->assertSame('not_available', data_get($payload, 'report_artifacts_archive.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_archive.durable_receipt_source'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.target_disk'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.latest_generated_at'));
        $this->assertNull(data_get($payload, 'report_artifacts_archive.latest_mode'));
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
        $this->assertSame('unknown_freshness', data_get($payload, 'runtime_truth.freshness_state'));
        $this->assertSame('unknown_freshness', data_get($payload, 'automation_readiness.freshness_state'));
        $this->assertSame('degraded', data_get($payload, 'attention_digest.overall_state'));
        $this->assertSame([
            'inventory',
            'report_artifacts_posture.archive',
            'report_artifacts_posture.rehydrate',
            'report_artifacts_posture.shrink',
            'reports_artifacts_lifecycle',
        ], data_get($payload, 'attention_digest.not_available_sections'));
        $this->assertContains('rehydrate', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertContains('retention.scopes.reports_backups', data_get($payload, 'attention_digest.never_run_sections', []));
        $this->assertSame([], data_get($payload, 'attention_digest.stale_sections'));
        $this->assertSame(5, data_get($payload, 'attention_digest.counts.not_available'));
        $this->assertGreaterThan(0, (int) data_get($payload, 'attention_digest.counts.never_run'));
        $attentionMessages = array_values(array_filter(array_map(
            static fn ($item): ?string => is_array($item) ? ($item['message'] ?? null) : null,
            (array) data_get($payload, 'attention_digest.attention_items', [])
        )));
        $this->assertContains('inventory is not available', $attentionMessages);
        $this->assertContains('report artifacts archive posture is not available', $attentionMessages);
        $this->assertContains('report artifacts rehydrate posture is not available', $attentionMessages);
        $this->assertContains('report artifacts shrink posture is not available', $attentionMessages);
        $this->assertContains('reports artifacts lifecycle is not available', $attentionMessages);
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
     * @return array{plan_path:string,run_path:string}
     */
    private function seedReportArtifactsArchiveTruth(bool $runPathExists = true): array
    {
        $planPath = storage_path('app/private/report_artifact_archive_plans/archive-plan.json');
        $runPath = storage_path('app/private/report_artifact_archive_runs/archive-run/run.json');
        $this->writeJson($planPath, [
            'schema' => 'storage_archive_report_artifacts_plan.v1',
            'mode' => 'dry_run',
        ]);

        if ($runPathExists) {
            $this->writeJson($runPath, [
                'schema' => 'storage_archive_report_artifacts_run.v1',
                'mode' => 'execute',
            ]);
        }

        $this->insertAudit('storage_archive_report_artifacts', 'report_artifacts_archive', [
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
            'summary' => [
                'candidate_count' => 3,
                'copied_count' => 2,
                'verified_count' => 3,
                'already_archived_count' => 1,
                'failed_count' => 0,
            ],
            'results' => [
                ['status' => 'copied'],
                ['status' => 'copied'],
                ['status' => 'already_archived'],
            ],
        ], 'success', 'manual_archive_copy', now()->subMinutes(10));

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
        $this->writeJson($planPath, [
            'schema' => 'storage_rehydrate_report_artifacts_plan.v1',
            'mode' => 'dry_run',
        ]);

        $this->insertAudit('storage_rehydrate_report_artifacts', 'report_artifacts_rehydrate', [
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
            'summary' => [
                'candidate_count' => 3,
                'rehydrated_count' => 0,
                'verified_count' => 0,
                'skipped_count' => 2,
                'blocked_count' => 1,
                'failed_count' => 0,
            ],
        ], 'success', 'manual_archive_rehydrate', now()->subMinutes(9));

        return ['plan_path' => $planPath];
    }

    /**
     * @return array{plan_path:string}
     */
    private function seedReportArtifactsShrinkTruth(): array
    {
        $planPath = storage_path('app/private/report_artifact_shrink_plans/shrink-plan.json');
        $this->writeJson($planPath, [
            'schema' => 'storage_shrink_archived_report_artifacts_plan.v1',
            'mode' => 'dry_run',
        ]);

        $this->insertAudit('storage_shrink_archived_report_artifacts', 'report_artifacts_shrink', [
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
            'summary' => [
                'candidate_count' => 3,
                'deleted_count' => 0,
                'skipped_missing_local_count' => 0,
                'blocked_missing_remote_count' => 1,
                'blocked_missing_archive_proof_count' => 2,
                'blocked_missing_rehydrate_proof_count' => 0,
                'blocked_hash_mismatch_count' => 1,
                'failed_count' => 0,
            ],
        ], 'success', 'manual_archive_backed_shrink', now()->subMinutes(8));

        return ['plan_path' => $planPath];
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
}
