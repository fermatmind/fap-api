<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptChainAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_recent_chain_findings_and_orphans(): void
    {
        $now = now();

        DB::table('attempts')->insert([
            [
                'id' => 'attempt-healthy',
                'anon_id' => 'anon-healthy',
                'user_id' => null,
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'scale_version' => 'v1',
                'question_count' => 12,
                'answers_summary_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => '1.0.0',
                'channel' => 'organic',
                'referrer' => null,
                'started_at' => $now->copy()->subMinutes(40),
                'submitted_at' => $now->copy()->subMinutes(39),
                'created_at' => $now->copy()->subMinutes(40),
                'updated_at' => $now->copy()->subMinutes(39),
            ],
            [
                'id' => 'attempt-missing-submission',
                'anon_id' => 'anon-ms',
                'user_id' => null,
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'scale_version' => 'v1',
                'question_count' => 12,
                'answers_summary_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => '1.0.0',
                'channel' => 'organic',
                'referrer' => null,
                'started_at' => $now->copy()->subMinutes(35),
                'submitted_at' => $now->copy()->subMinutes(34),
                'created_at' => $now->copy()->subMinutes(35),
                'updated_at' => $now->copy()->subMinutes(34),
            ],
            [
                'id' => 'attempt-succeeded-no-result',
                'anon_id' => 'anon-snr',
                'user_id' => null,
                'org_id' => 0,
                'scale_code' => 'BIG5_OCEAN',
                'scale_version' => 'v1',
                'question_count' => 10,
                'answers_summary_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => '1.0.0',
                'channel' => 'organic',
                'referrer' => null,
                'started_at' => $now->copy()->subMinutes(30),
                'submitted_at' => $now->copy()->subMinutes(29),
                'created_at' => $now->copy()->subMinutes(30),
                'updated_at' => $now->copy()->subMinutes(29),
            ],
            [
                'id' => 'attempt-result-no-projection',
                'anon_id' => 'anon-rnp',
                'user_id' => null,
                'org_id' => 0,
                'scale_code' => 'EQ_60',
                'scale_version' => 'v1',
                'question_count' => 8,
                'answers_summary_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => '1.0.0',
                'channel' => 'organic',
                'referrer' => null,
                'started_at' => $now->copy()->subMinutes(25),
                'submitted_at' => $now->copy()->subMinutes(24),
                'created_at' => $now->copy()->subMinutes(25),
                'updated_at' => $now->copy()->subMinutes(24),
            ],
            [
                'id' => 'attempt-pending-stuck',
                'anon_id' => 'anon-pending',
                'user_id' => null,
                'org_id' => 0,
                'scale_code' => 'IQ_RAVEN',
                'scale_version' => 'v1',
                'question_count' => 6,
                'answers_summary_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => '1.0.0',
                'channel' => 'organic',
                'referrer' => null,
                'started_at' => $now->copy()->subMinutes(20),
                'submitted_at' => $now->copy()->subMinutes(19),
                'created_at' => $now->copy()->subMinutes(20),
                'updated_at' => $now->copy()->subMinutes(19),
            ],
        ]);

        DB::table('attempt_submissions')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => 'attempt-healthy',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon-healthy',
                'dedupe_key' => hash('sha256', 'attempt-healthy'),
                'mode' => 'async',
                'state' => 'succeeded',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => null,
                'response_payload_json' => null,
                'started_at' => $now->copy()->subMinutes(39),
                'finished_at' => $now->copy()->subMinutes(38),
                'created_at' => $now->copy()->subMinutes(39),
                'updated_at' => $now->copy()->subMinutes(38),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => 'attempt-succeeded-no-result',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon-snr',
                'dedupe_key' => hash('sha256', 'attempt-succeeded-no-result'),
                'mode' => 'async',
                'state' => 'succeeded',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => null,
                'response_payload_json' => null,
                'started_at' => $now->copy()->subMinutes(29),
                'finished_at' => $now->copy()->subMinutes(28),
                'created_at' => $now->copy()->subMinutes(29),
                'updated_at' => $now->copy()->subMinutes(28),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => 'attempt-result-no-projection',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon-rnp',
                'dedupe_key' => hash('sha256', 'attempt-result-no-projection'),
                'mode' => 'async',
                'state' => 'succeeded',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => null,
                'response_payload_json' => null,
                'started_at' => $now->copy()->subMinutes(24),
                'finished_at' => $now->copy()->subMinutes(23),
                'created_at' => $now->copy()->subMinutes(24),
                'updated_at' => $now->copy()->subMinutes(23),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => 'attempt-pending-stuck',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon-pending',
                'dedupe_key' => hash('sha256', 'attempt-pending-stuck'),
                'mode' => 'async',
                'state' => 'pending',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => null,
                'response_payload_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now->copy()->subMinutes(18),
                'updated_at' => $now->copy()->subMinutes(18),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => 'attempt-orphan-submission',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon-orphan',
                'dedupe_key' => hash('sha256', 'attempt-orphan-submission'),
                'mode' => 'async',
                'state' => 'pending',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => null,
                'response_payload_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now->copy()->subMinutes(10),
                'updated_at' => $now->copy()->subMinutes(10),
            ],
        ]);

        DB::table('results')->insert([
            [
                'id' => (string) Str::uuid(),
                'attempt_id' => 'attempt-healthy',
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'scale_version' => 'v1',
                'type_code' => 'INTJ-A',
                'scores_json' => json_encode(['EI' => 10], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'profile_version' => 'mbti-v1',
                'is_valid' => 1,
                'computed_at' => $now->copy()->subMinutes(38),
                'created_at' => $now->copy()->subMinutes(38),
                'updated_at' => $now->copy()->subMinutes(38),
            ],
            [
                'id' => (string) Str::uuid(),
                'attempt_id' => 'attempt-result-no-projection',
                'org_id' => 0,
                'scale_code' => 'EQ_60',
                'scale_version' => 'v1',
                'type_code' => 'EQ-READY',
                'scores_json' => json_encode(['EQ' => 88], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'profile_version' => 'eq-v1',
                'is_valid' => 1,
                'computed_at' => $now->copy()->subMinutes(23),
                'created_at' => $now->copy()->subMinutes(23),
                'updated_at' => $now->copy()->subMinutes(23),
            ],
            [
                'id' => (string) Str::uuid(),
                'attempt_id' => 'attempt-orphan-result',
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'scale_version' => 'v1',
                'type_code' => 'ENTP-A',
                'scores_json' => json_encode(['EI' => 11], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'profile_version' => 'mbti-v1',
                'is_valid' => 1,
                'computed_at' => $now->copy()->subMinutes(9),
                'created_at' => $now->copy()->subMinutes(9),
                'updated_at' => $now->copy()->subMinutes(9),
            ],
        ]);

        DB::table('unified_access_projections')->insert([
            [
                'attempt_id' => 'attempt-healthy',
                'access_state' => 'available',
                'report_state' => 'ready',
                'pdf_state' => 'ready',
                'reason_code' => null,
                'projection_version' => 1,
                'actions_json' => null,
                'payload_json' => json_encode([
                    'unlock_stage' => 'partial',
                    'unlock_source' => 'invite',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'produced_at' => $now->copy()->subMinutes(38),
                'refreshed_at' => $now->copy()->subMinutes(38),
                'created_at' => $now->copy()->subMinutes(38),
                'updated_at' => $now->copy()->subMinutes(38),
            ],
            [
                'attempt_id' => 'attempt-orphan-projection',
                'access_state' => 'available',
                'report_state' => 'ready',
                'pdf_state' => 'ready',
                'reason_code' => null,
                'projection_version' => 1,
                'actions_json' => null,
                'payload_json' => null,
                'produced_at' => $now->copy()->subMinutes(8),
                'refreshed_at' => $now->copy()->subMinutes(8),
                'created_at' => $now->copy()->subMinutes(8),
                'updated_at' => $now->copy()->subMinutes(8),
            ],
        ]);

        DB::table('attempt_invite_unlocks')->insert([
            'id' => (string) Str::uuid(),
            'target_org_id' => 0,
            'invite_code' => 'iul_audit_test_001',
            'target_attempt_id' => 'attempt-healthy',
            'target_scale_code' => 'MBTI',
            'inviter_user_id' => null,
            'inviter_anon_id' => 'anon-healthy',
            'status' => 'in_progress',
            'required_invitees' => 2,
            'completed_invitees' => 1,
            'qualification_rule_version' => 'v1',
            'meta_json' => null,
            'created_at' => $now->copy()->subMinutes(38),
            'updated_at' => $now->copy()->subMinutes(5),
        ]);

        $inviteRow = DB::table('attempt_invite_unlocks')->where('invite_code', 'iul_audit_test_001')->first();
        $this->assertNotNull($inviteRow);

        DB::table('attempt_invite_unlock_completions')->insert([
            [
                'id' => (string) Str::uuid(),
                'invite_id' => (string) $inviteRow->id,
                'invite_code' => 'iul_audit_test_001',
                'target_attempt_id' => 'attempt-healthy',
                'invitee_attempt_id' => 'attempt-result-no-projection',
                'invitee_org_id' => 0,
                'invitee_user_id' => null,
                'invitee_anon_id' => 'anon-rnp',
                'invitee_identity_key' => 'anon:anon-rnp',
                'qualified' => 1,
                'qualified_reason' => 'qualified_counted',
                'qualification_status' => 'qualified_counted',
                'counted' => 1,
                'counted_identity_key' => 'anon:anon-rnp',
                'idempotency_key' => 'invite_completion:'.hash('sha256', 'attempt-healthy|attempt-result-no-projection'),
                'meta_json' => null,
                'created_at' => $now->copy()->subMinutes(4),
                'updated_at' => $now->copy()->subMinutes(4),
            ],
            [
                'id' => (string) Str::uuid(),
                'invite_id' => (string) $inviteRow->id,
                'invite_code' => 'iul_audit_test_001',
                'target_attempt_id' => 'attempt-healthy',
                'invitee_attempt_id' => null,
                'invitee_org_id' => null,
                'invitee_user_id' => null,
                'invitee_anon_id' => 'anon-healthy',
                'invitee_identity_key' => 'anon:anon-healthy',
                'qualified' => 0,
                'qualified_reason' => 'rejected_self_referral',
                'qualification_status' => 'rejected_self_referral',
                'counted' => 0,
                'counted_identity_key' => null,
                'idempotency_key' => 'rejected_self_referral:'.hash('sha256', 'attempt-healthy|self'),
                'meta_json' => null,
                'created_at' => $now->copy()->subMinutes(3),
                'updated_at' => $now->copy()->subMinutes(3),
            ],
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => 'anon-healthy',
            'benefit_code' => 'MBTI_CAREER',
            'scope' => 'attempt',
            'attempt_id' => 'attempt-healthy',
            'order_no' => null,
            'status' => 'active',
            'expires_at' => null,
            'benefit_ref' => 'anon-healthy',
            'benefit_type' => 'report_unlock',
            'meta_json' => json_encode(['granted_via' => 'invite_unlock', 'invite_unlock_stage' => 'partial'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'created_at' => $now->copy()->subMinutes(4),
            'updated_at' => $now->copy()->subMinutes(4),
        ]);

        $exitCode = Artisan::call('ops:attempt-chain-audit', [
            '--json' => 1,
            '--window-hours' => 24,
            '--limit' => 20,
            '--pending-timeout-minutes' => 15,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(8, (int) ($payload['inspected_count'] ?? 0));
        $this->assertSame(5, (int) data_get($payload, 'summary.critical_total', -1));
        $this->assertSame(2, (int) data_get($payload, 'summary.warning_total', -1));
        $this->assertSame(7, (int) data_get($payload, 'summary.finding_total', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.submission_missing_for_submitted_attempt', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.result_missing_after_submission_success', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.projection_missing_after_result', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.submission_stuck_pending', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.orphan_submission_without_attempt', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.orphan_result_without_attempt', -1));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.orphan_projection_without_attempt', -1));
        $inspectionForHealthy = collect((array) data_get($payload, 'inspections', []))
            ->first(fn ($inspection) => (bool) data_get($inspection, 'invite_unlock_diagnostic_v1.has_invite', false));
        $this->assertIsArray($inspectionForHealthy);
        $this->assertTrue((bool) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.has_invite', false));
        $this->assertSame('partial', (string) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.projection_unlock_stage', ''));
        $this->assertSame('invite', (string) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.projection_unlock_source', ''));
        $this->assertSame(1, (int) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.completed_invitees', -1));
        $this->assertSame(1, (int) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.rejections_by_status.rejected_self_referral', -1));
        $this->assertSame('MBTI_CAREER', (string) data_get($inspectionForHealthy, 'invite_unlock_diagnostic_v1.grants.0.benefit_code', ''));
    }

    public function test_command_exact_attempt_mode_reports_chain_absent_and_strict_fails(): void
    {
        $exitCode = Artisan::call('ops:attempt-chain-audit', [
            '--json' => 1,
            '--strict' => 1,
            '--attempt-id' => 'missing-attempt-123',
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('missing-attempt-123', (string) data_get($payload, 'selection.attempt_id', ''));
        $this->assertSame(1, (int) data_get($payload, 'summary.finding_total', 0));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.attempt_chain_absent', 0));

        $inspection = data_get($payload, 'inspections.0');
        $this->assertIsArray($inspection);
        $this->assertFalse((bool) data_get($inspection, 'attempt.present', true));
        $this->assertFalse((bool) data_get($inspection, 'submission.present', true));
        $this->assertFalse((bool) data_get($inspection, 'result.present', true));
        $this->assertFalse((bool) data_get($inspection, 'projection.present', true));
    }
}
