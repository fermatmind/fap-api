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

final class StorageControlPlaneReadCutoverTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-control-plane-cutover-'.Str::uuid();
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

    public function test_flag_off_preserves_legacy_audit_backed_status(): void
    {
        config()->set('storage_rollout.control_plane_read_from_catalog_enabled', false);

        $this->seedInventoryAudit();
        $this->seedLegacyArchiveAudit();

        $payload = app(StorageControlPlaneStatusService::class)->buildStatus();

        $this->assertSame('ok', data_get($payload, 'report_artifacts_archive.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_archive.durable_receipt_source'));
        $this->assertSame('s3', data_get($payload, 'report_artifacts_archive.target_disk'));
        $this->assertSame(2, data_get($payload, 'report_artifacts_archive.latest_summary.candidate_count'));
        $this->assertSame('ok', data_get($payload, 'reports_artifacts_lifecycle.status'));
        $this->assertSame('audit_logs.meta_json', data_get($payload, 'report_artifacts_posture.durable_receipt_source'));
        $this->assertSame('partial', data_get($payload, 'report_artifacts_posture.status'));
    }

    public function test_flag_on_prefers_catalog_backed_status_and_falls_back_when_catalog_missing(): void
    {
        config()->set('storage_rollout.control_plane_read_from_catalog_enabled', true);

        $this->seedInventoryAudit();
        $this->seedLegacyArchiveAudit();
        $this->seedLedgerArchiveRows();

        $payload = app(StorageControlPlaneStatusService::class)->buildStatus();

        $this->assertSame('ok', data_get($payload, 'reports_artifacts_lifecycle.status'));
        $this->assertSame('ledger_backed', data_get($payload, 'reports_artifacts_lifecycle.archive_candidate_status'));
        $this->assertSame(1, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.report_json_files'));
        $this->assertSame(1, data_get($payload, 'reports_artifacts_lifecycle.canonical_user_output.pdf_files'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_archive.status'));
        $this->assertSame('attempt_receipts', data_get($payload, 'report_artifacts_archive.durable_receipt_source'));
        $this->assertSame('local', data_get($payload, 'report_artifacts_archive.target_disk'));
        $this->assertSame('ok', data_get($payload, 'report_artifacts_posture.status'));
        $this->assertSame('attempt_receipts', data_get($payload, 'report_artifacts_posture.durable_receipt_source'));
        $this->assertSame('ledger-derived', data_get($payload, 'report_artifacts_posture.freshness_source_type'));
        $this->assertSame('ledger-derived', data_get($payload, 'reports_artifacts_lifecycle.freshness_source_type'));
    }

    private function seedInventoryAudit(): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_inventory',
            'target_type' => 'storage',
            'target_id' => 'inventory',
            'meta_json' => json_encode([
                'schema_version' => 2,
                'generated_at' => now()->subMinutes(10)->toIso8601String(),
                'focus_scopes' => ['reports', 'artifacts'],
                'totals' => ['files' => 3, 'bytes' => 1024],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test',
            'request_id' => null,
            'reason' => 'test',
            'result' => 'success',
            'created_at' => now()->subMinutes(10),
        ]);
    }

    private function seedLegacyArchiveAudit(): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_archive_report_artifacts',
            'target_type' => 'report_artifact_archive',
            'target_id' => 'archive_report_artifacts',
            'meta_json' => json_encode([
                'durable_receipt_source' => 'audit_logs.meta_json',
                'target_disk' => 's3',
                'mode' => 'execute',
                'plan_path' => storage_path('app/private/report_artifact_archive_plans/archive-plan.json'),
                'run_path' => storage_path('app/private/report_artifact_archive_runs/archive-run.json'),
                'candidate_count' => 2,
                'copied_count' => 1,
                'verified_count' => 1,
                'already_archived_count' => 0,
                'failed_count' => 0,
                'results' => [
                    ['attempt_id' => 'attempt-legacy', 'status' => 'copied'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test',
            'request_id' => null,
            'reason' => 'test',
            'result' => 'success',
            'created_at' => now()->subMinutes(5),
        ]);
    }

    private function seedLedgerArchiveRows(): void
    {
        $now = now();
        $slotIds = [];

        foreach ([
            ['report_json_free', 120],
            ['report_pdf_full', 240],
        ] as [$slotCode, $byteSize]) {
            $slotId = DB::table('report_artifact_slots')->insertGetId([
                'attempt_id' => 'attempt-ledger-cutover',
                'slot_code' => $slotCode,
                'required_by_product' => false,
                'current_version_id' => null,
                'render_state' => 'materialized',
                'delivery_state' => 'available',
                'access_state' => 'locked',
                'integrity_state' => 'verified',
                'last_error_code' => null,
                'last_materialized_at' => $now,
                'last_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $versionId = DB::table('report_artifact_versions')->insertGetId([
                'artifact_slot_id' => $slotId,
                'version_no' => 1,
                'source_type' => 'file',
                'report_snapshot_id' => 'attempt-ledger-cutover',
                'storage_blob_id' => str_repeat('a', 64),
                'created_from_receipt_id' => null,
                'supersedes_version_id' => null,
                'manifest_hash' => 'nohash',
                'dir_version' => 'v1',
                'scoring_spec_version' => 'spec-v1',
                'report_engine_version' => 'engine-v1',
                'content_hash' => hash('sha256', $slotCode),
                'byte_size' => $byteSize,
                'metadata_json' => json_encode(['slot_code' => $slotCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('report_artifact_slots')
                ->where('id', $slotId)
                ->update([
                    'current_version_id' => $versionId,
                    'updated_at' => $now,
                ]);

            $slotIds[] = $slotId;
        }

        DB::table('artifact_lifecycle_jobs')->insert([
            'attempt_id' => 'attempt-ledger-cutover',
            'artifact_slot_id' => $slotIds[0],
            'job_type' => 'archive_report_artifacts',
            'state' => 'succeeded',
            'reason_code' => null,
            'blocked_reason_code' => null,
            'idempotency_key' => 'archive-ledger-cutover',
            'request_payload_json' => json_encode([
                'mode' => 'execute',
                'plan_path' => storage_path('app/private/report_artifact_archive_plans/ledger-plan.json'),
                'target_disk' => 'local',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_payload_json' => json_encode([
                'mode' => 'execute',
                'run_path' => storage_path('app/private/report_artifact_archive_runs/ledger-run.json'),
                'target_disk' => 'local',
                'summary' => [
                    'candidate_count' => 2,
                    'copied_count' => 1,
                    'verified_count' => 1,
                    'already_archived_count' => 0,
                    'failed_count' => 0,
                ],
                'results' => [
                    ['attempt_id' => 'attempt-ledger-cutover', 'status' => 'copied'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempt_count' => 1,
            'started_at' => $now->subMinutes(3),
            'finished_at' => $now->subMinutes(3),
            'created_at' => $now->subMinutes(3),
            'updated_at' => $now->subMinutes(3),
        ]);

        DB::table('artifact_lifecycle_jobs')->insert([
            'attempt_id' => 'attempt-ledger-cutover',
            'artifact_slot_id' => $slotIds[0],
            'job_type' => 'rehydrate_report_artifacts',
            'state' => 'succeeded',
            'reason_code' => null,
            'blocked_reason_code' => null,
            'idempotency_key' => 'rehydrate-ledger-cutover',
            'request_payload_json' => json_encode([
                'mode' => 'execute',
                'plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/ledger-plan.json'),
                'target_disk' => 'local',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_payload_json' => json_encode([
                'mode' => 'execute',
                'run_path' => storage_path('app/private/report_artifact_rehydrate_runs/ledger-run.json'),
                'summary' => [
                    'candidate_count' => 1,
                    'rehydrated_count' => 1,
                    'verified_count' => 1,
                    'skipped_count' => 0,
                    'blocked_count' => 0,
                    'failed_count' => 0,
                ],
                'results' => [
                    ['attempt_id' => 'attempt-ledger-cutover', 'status' => 'rehydrated'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempt_count' => 1,
            'started_at' => $now->subMinutes(2),
            'finished_at' => $now->subMinutes(2),
            'created_at' => $now->subMinutes(2),
            'updated_at' => $now->subMinutes(2),
        ]);

        DB::table('artifact_lifecycle_jobs')->insert([
            'attempt_id' => 'attempt-ledger-cutover',
            'artifact_slot_id' => $slotIds[1],
            'job_type' => 'shrink_archived_report_artifacts',
            'state' => 'succeeded',
            'reason_code' => null,
            'blocked_reason_code' => null,
            'idempotency_key' => 'shrink-ledger-cutover',
            'request_payload_json' => json_encode([
                'mode' => 'execute',
                'plan_path' => storage_path('app/private/report_artifact_shrink_plans/ledger-plan.json'),
                'target_disk' => 'local',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_payload_json' => json_encode([
                'mode' => 'execute',
                'run_path' => storage_path('app/private/report_artifact_shrink_runs/ledger-run.json'),
                'summary' => [
                    'candidate_count' => 1,
                    'deleted_count' => 1,
                    'skipped_missing_local_count' => 0,
                    'blocked_missing_remote_count' => 0,
                    'blocked_missing_archive_proof_count' => 0,
                    'blocked_missing_rehydrate_proof_count' => 0,
                    'blocked_hash_mismatch_count' => 0,
                    'failed_count' => 0,
                ],
                'results' => [
                    ['attempt_id' => 'attempt-ledger-cutover', 'status' => 'shrunk'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempt_count' => 1,
            'started_at' => $now->subMinute(),
            'finished_at' => $now->subMinute(),
            'created_at' => $now->subMinute(),
            'updated_at' => $now->subMinute(),
        ]);

        DB::table('attempt_receipts')->insert([
            'attempt_id' => 'attempt-ledger-cutover',
            'seq' => 1,
            'receipt_type' => 'artifact_archived',
            'source_system' => 'ledger_cutover_test',
            'source_ref' => 'ledger-cutover',
            'actor_type' => 'system',
            'actor_id' => 'ledger_cutover_test',
            'idempotency_key' => 'ledger-cutover-archive',
            'payload_json' => json_encode(['status' => 'copied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now->subMinutes(3),
            'recorded_at' => $now->subMinutes(3),
            'created_at' => $now->subMinutes(3),
            'updated_at' => $now->subMinutes(3),
        ]);
    }
}
