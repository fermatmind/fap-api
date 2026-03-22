<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptReceiptBackfillReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_replay_is_idempotent_and_preserves_receipt_types(): void
    {
        $attemptId = 'attempt-receipt-'.Str::lower(Str::random(8));
        $auditLogId = (string) Str::uuid();

        DB::table('audit_logs')->insert([
            'id' => 1,
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_archive_report_artifacts',
            'target_type' => 'attempt',
            'target_id' => $attemptId,
            'meta_json' => json_encode([
                'results' => [
                    [
                        'attempt_id' => $attemptId,
                        'status' => 'copied',
                        'kind' => 'report_json',
                        'source_path' => 'artifacts/reports/MBTI/'.$attemptId.'/report.json',
                        'target_object_key' => 'report_artifacts_archive/'.$attemptId.'/report.json',
                        'target_disk' => 's3',
                    ],
                ],
                'summary' => [
                    'candidate_count' => 1,
                    'copied_count' => 1,
                    'verified_count' => 1,
                    'already_archived_count' => 0,
                    'failed_count' => 0,
                    'results_count' => 1,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test',
            'request_id' => $auditLogId,
            'reason' => 'test',
            'result' => 'success',
            'created_at' => now()->subMinutes(3),
        ]);

        DB::table('audit_logs')->insert([
            'id' => 2,
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_shrink_archived_report_artifacts',
            'target_type' => 'attempt',
            'target_id' => $attemptId,
            'meta_json' => json_encode([
                'results' => [
                    [
                        'attempt_id' => $attemptId,
                        'status' => 'deleted',
                        'kind' => 'report_pdf',
                        'source_path' => 'artifacts/pdf/MBTI/'.$attemptId.'/nohash/report_full.pdf',
                        'target_object_key' => 'report_artifacts_archive/'.$attemptId.'/report_full.pdf',
                        'target_disk' => 's3',
                    ],
                ],
                'summary' => [
                    'candidate_count' => 1,
                    'deleted_count' => 1,
                    'skipped_missing_local_count' => 0,
                    'blocked_missing_remote_count' => 0,
                    'blocked_missing_archive_proof_count' => 0,
                    'blocked_missing_rehydrate_proof_count' => 0,
                    'blocked_hash_mismatch_count' => 0,
                    'failed_count' => 0,
                    'results_count' => 1,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test',
            'request_id' => (string) Str::uuid(),
            'reason' => 'test',
            'result' => 'success',
            'created_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, Artisan::call('storage:backfill-attempt-receipts', [
            '--dry-run' => true,
            '--attempt-id' => $attemptId,
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('mode=dry_run', $dryRunOutput);
        $this->assertStringContainsString('receipt_candidates=2', $dryRunOutput);
        $this->assertStringContainsString('unique_attempt_ids=1', $dryRunOutput);
        $this->assertStringContainsString('"artifact_archived":1', $dryRunOutput);
        $this->assertStringContainsString('"artifact_shrunk":1', $dryRunOutput);

        $this->assertSame(0, Artisan::call('storage:backfill-attempt-receipts', [
            '--execute' => true,
            '--attempt-id' => $attemptId,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('mode=execute', $executeOutput);
        $this->assertStringContainsString('attempt_receipts_inserted=2', $executeOutput);
        $this->assertStringContainsString('attempt_receipts_reused=0', $executeOutput);
        $this->assertDatabaseCount('attempt_receipts', 2);

        $this->assertSame(0, Artisan::call('storage:backfill-attempt-receipts', [
            '--execute' => true,
            '--attempt-id' => $attemptId,
        ]));
        $rerunOutput = Artisan::output();
        $this->assertStringContainsString('attempt_receipts_inserted=0', $rerunOutput);
        $this->assertStringContainsString('attempt_receipts_reused=2', $rerunOutput);
        $this->assertDatabaseCount('attempt_receipts', 2);
    }
}
