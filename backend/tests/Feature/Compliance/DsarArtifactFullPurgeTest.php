<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptDataLifecycleService;
use App\Services\Storage\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DsarArtifactFullPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_artifact_purge_removes_canonical_and_cataloged_artifacts_when_flag_is_enabled(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        config()->set('storage_rollout.retention_policy_engine_enabled', true);
        config()->set('storage_rollout.lifecycle_front_door_enabled', true);
        config()->set('storage_rollout.dsar_artifact_purge_enabled', true);

        DB::table('retention_policies')->insert([
            'code' => 'dsar_full_purge_default',
            'subject_scope' => 'attempt',
            'artifact_scope' => 'report_artifact_domain',
            'archive_after_days' => null,
            'shrink_after_days' => null,
            'purge_after_days' => 0,
            'delete_behavior' => 'purge_all',
            'delete_remote_archive' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 919;
        $attemptId = (string) Str::uuid();
        $manifestHash = 'purgehashv1';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'anon_purge_full',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'pack_release_manifest_hash' => $manifestHash,
                ],
            ],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20]],
            'scores_pct' => ['EI' => 50],
            'axis_states' => ['EI' => 'clear'],
            'result_json' => ['type_code' => 'INTJ-A'],
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        /** @var ArtifactStore $artifactStore */
        $artifactStore = app(ArtifactStore::class);
        $reportPath = $artifactStore->reportCanonicalPath('MBTI', $attemptId);
        $pdfPath = $artifactStore->pdfCanonicalPath('MBTI', $attemptId, $manifestHash, 'free');
        $reportBytes = '{"artifact":"report"}';
        $pdfBytes = '%PDF-1.4 artifact';
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('local')->put($pdfPath, $pdfBytes);

        $remoteReportPath = 'report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json';
        $remotePdfPath = 'report_artifacts_archive/pdf/MBTI/'.$attemptId.'/'.$manifestHash.'/report_free.pdf';
        Storage::disk('s3')->put($remoteReportPath, $reportBytes);
        Storage::disk('s3')->put($remotePdfPath, $pdfBytes);

        $reportHash = hash('sha256', $reportBytes);
        $pdfHash = hash('sha256', $pdfBytes);

        DB::table('storage_blobs')->insert([
            [
                'hash' => $reportHash,
                'disk' => 'local',
                'storage_path' => 'blobs/'.$reportHash,
                'size_bytes' => strlen($reportBytes),
                'content_type' => 'application/json',
                'encoding' => 'identity',
                'ref_count' => 0,
                'first_seen_at' => now(),
                'last_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'hash' => $pdfHash,
                'disk' => 'local',
                'storage_path' => 'blobs/'.$pdfHash,
                'size_bytes' => strlen($pdfBytes),
                'content_type' => 'application/pdf',
                'encoding' => 'identity',
                'ref_count' => 0,
                'first_seen_at' => now(),
                'last_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('storage_blob_locations')->insert([
            [
                'blob_hash' => $reportHash,
                'disk' => 'local',
                'storage_path' => $reportPath,
                'location_kind' => 'canonical_file',
                'size_bytes' => strlen($reportBytes),
                'verified_at' => now(),
                'meta_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'blob_hash' => $reportHash,
                'disk' => 's3',
                'storage_path' => $remoteReportPath,
                'location_kind' => 'remote_copy',
                'size_bytes' => strlen($reportBytes),
                'verified_at' => now(),
                'meta_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'blob_hash' => $pdfHash,
                'disk' => 'local',
                'storage_path' => $pdfPath,
                'location_kind' => 'canonical_file',
                'size_bytes' => strlen($pdfBytes),
                'verified_at' => now(),
                'meta_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'blob_hash' => $pdfHash,
                'disk' => 's3',
                'storage_path' => $remotePdfPath,
                'location_kind' => 'remote_copy',
                'size_bytes' => strlen($pdfBytes),
                'verified_at' => now(),
                'meta_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $jsonSlotId = DB::table('report_artifact_slots')->insertGetId([
            'attempt_id' => $attemptId,
            'slot_code' => 'report_json_full',
            'required_by_product' => true,
            'current_version_id' => null,
            'render_state' => 'materialized',
            'delivery_state' => 'available',
            'access_state' => 'ready',
            'integrity_state' => 'verified',
            'last_materialized_at' => now(),
            'last_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pdfSlotId = DB::table('report_artifact_slots')->insertGetId([
            'attempt_id' => $attemptId,
            'slot_code' => 'report_pdf_free',
            'required_by_product' => true,
            'current_version_id' => null,
            'render_state' => 'materialized',
            'delivery_state' => 'available',
            'access_state' => 'ready',
            'integrity_state' => 'verified',
            'last_materialized_at' => now(),
            'last_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_artifact_versions')->insert([
            [
                'artifact_slot_id' => $jsonSlotId,
                'version_no' => 1,
                'source_type' => 'report_json',
                'storage_blob_id' => $reportHash,
                'manifest_hash' => $manifestHash,
                'content_hash' => $reportHash,
                'byte_size' => strlen($reportBytes),
                'metadata_json' => json_encode(['source' => 'phpunit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'artifact_slot_id' => $pdfSlotId,
                'version_no' => 1,
                'source_type' => 'report_pdf',
                'storage_blob_id' => $pdfHash,
                'manifest_hash' => $manifestHash,
                'content_hash' => $pdfHash,
                'byte_size' => strlen($pdfBytes),
                'metadata_json' => json_encode(['source' => 'phpunit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /** @var AttemptDataLifecycleService $service */
        $service = app(AttemptDataLifecycleService::class);
        $result = $service->purgeAttempt($attemptId, $orgId, [
            'reason' => 'user_request',
            'scale_code' => 'MBTI',
            'actor_user_id' => 9001,
            'request_id' => 'req-full-purge',
            'task_id' => 'task-full-purge',
            'reference_id' => 'ref-full-purge',
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertFalse(Storage::disk('local')->exists($reportPath));
        $this->assertFalse(Storage::disk('local')->exists($pdfPath));
        Storage::disk('s3')->assertMissing($remoteReportPath);
        Storage::disk('s3')->assertMissing($remotePdfPath);
        $this->assertDatabaseCount('storage_blob_locations', 0);
        $this->assertDatabaseCount('storage_blobs', 0);
        $this->assertDatabaseCount('report_artifact_slots', 0);
        $this->assertDatabaseCount('report_artifact_versions', 0);
        $this->assertDatabaseHas('artifact_lifecycle_jobs', [
            'job_type' => 'dsar_purge_report_artifacts',
            'state' => 'succeeded',
            'attempt_id' => $attemptId,
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_purged',
        ]);

        $requestRow = DB::table('data_lifecycle_requests')
            ->where('request_type', 'attempt_purge')
            ->where('subject_ref', $attemptId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($requestRow);
        $requestResult = json_decode((string) ($requestRow->result_json ?? '{}'), true);
        $this->assertIsArray($requestResult);
        $this->assertSame('no_residual_found', (string) data_get($requestResult, 'artifact_residual_audit.state'));
    }
}
