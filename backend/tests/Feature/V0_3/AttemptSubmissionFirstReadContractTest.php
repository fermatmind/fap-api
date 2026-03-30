<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptSubmissionFirstReadContractTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttempt(string $attemptId, string $anonId, string $scaleCode = 'MBTI'): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(2),
            'submitted_at' => now()->subMinute(),
            'pack_id' => "{$scaleCode}.pack",
            'dir_version' => "{$scaleCode}.dir",
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createSubmission(string $attemptId, string $anonId, string $state, ?array $responsePayload = null): string
    {
        $submissionId = (string) Str::uuid();

        DB::table('attempt_submissions')->insert([
            'id' => $submissionId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => $anonId,
            'dedupe_key' => hash('sha256', "{$attemptId}:{$state}"),
            'mode' => 'async',
            'state' => $state,
            'error_code' => $state === 'failed' ? 'SUBMISSION_JOB_TERMINAL_FAILURE' : null,
            'error_message' => $state === 'failed' ? 'submission job terminal failure.' : null,
            'request_payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => $responsePayload !== null
                ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'started_at' => now()->subMinute(),
            'finished_at' => in_array($state, ['succeeded', 'failed'], true) ? now()->subSeconds(10) : null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSeconds(5),
        ]);

        return $submissionId;
    }

    private function createResult(string $attemptId, string $scaleCode = 'MBTI'): void
    {
        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'result-v1',
            'result_json' => ['type_code' => 'INTJ-A', 'source' => 'stale_result'],
            'pack_id' => "{$scaleCode}.result-pack",
            'dir_version' => "{$scaleCode}.result-dir",
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now()->subSeconds(20),
        ]);
    }

    private function createProjection(string $attemptId, string $accessState = 'ready', string $reportState = 'ready'): void
    {
        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => 'ready',
            'reason_code' => 'report_ready',
            'projection_version' => 1,
            'actions_json' => json_encode(['report' => true, 'pdf' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode(['attempt_id' => $attemptId, 'has_active_grant' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'produced_at' => now()->subSeconds(15),
            'refreshed_at' => now()->subSeconds(10),
            'created_at' => now()->subSeconds(15),
            'updated_at' => now()->subSeconds(10),
        ]);
    }

    public function test_result_returns_generating_placeholder_when_submission_is_pending_and_result_is_missing(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_result_pending';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'pending');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(202);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => true,
            'submission_state' => 'pending',
            'result' => null,
        ]);
        $response->assertJsonPath('submission.id', $submissionId);
    }

    public function test_result_returns_terminal_failure_contract_when_submission_failed_and_result_is_missing(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_result_failed';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'failed', [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => false,
            'attempt_id' => $attemptId,
            'error_code' => 'SUBMISSION_FAILED',
            'generating' => false,
            'submission_state' => 'failed',
            'submission' => [
                'id' => $submissionId,
                'state' => 'failed',
                'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            ],
            'result' => [
                'ok' => false,
                'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
                'terminal_failure' => true,
            ],
        ]);
    }

    public function test_result_prefers_pending_submission_over_existing_stale_result(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_result_stale_pending';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'pending');
        $this->createResult($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(202);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => true,
            'submission_state' => 'pending',
            'result' => null,
        ]);
        $response->assertJsonPath('submission.id', $submissionId);
    }

    public function test_report_returns_terminal_failure_contract_when_submission_failed_and_result_is_missing(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_report_failed';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'failed', [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => false,
            'attempt_id' => $attemptId,
            'error_code' => 'SUBMISSION_FAILED',
            'generating' => false,
            'submission_state' => 'failed',
            'submission' => [
                'id' => $submissionId,
                'state' => 'failed',
                'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            ],
            'result' => [
                'ok' => false,
                'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
                'terminal_failure' => true,
            ],
            'report' => [],
        ]);
    }

    public function test_report_prefers_failed_submission_over_existing_stale_result(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_report_stale_failed';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'failed', [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);
        $this->createResult($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => false,
            'attempt_id' => $attemptId,
            'error_code' => 'SUBMISSION_FAILED',
            'submission_state' => 'failed',
            'submission' => [
                'id' => $submissionId,
                'state' => 'failed',
            ],
            'report' => [],
        ]);
    }

    public function test_report_access_returns_submission_pending_fallback_when_projection_and_result_are_missing(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_access_pending';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'pending');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'pending',
            'pdf_state' => 'missing',
            'reason_code' => 'submission_pending',
        ]);
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.submission.id', $submissionId);
        $response->assertJsonPath('actions.wait_href', "/result/{$attemptId}");
    }

    public function test_report_access_prefers_pending_submission_over_existing_ready_projection(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_access_stale_pending';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'pending');
        $this->createResult($attemptId);
        $this->createProjection($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'pending',
            'reason_code' => 'submission_pending',
        ]);
        $response->assertJsonPath('payload.submission.id', $submissionId);
        $response->assertJsonPath('actions.wait_href', "/result/{$attemptId}");
    }

    public function test_report_access_returns_submission_failed_fallback_when_projection_and_result_are_missing(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_access_failed';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'failed', [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'unavailable',
            'pdf_state' => 'missing',
            'reason_code' => 'submission_failed',
        ]);
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.submission.id', $submissionId);
        $response->assertJsonPath('payload.result.error_code', 'SUBMISSION_JOB_TERMINAL_FAILURE');
        $response->assertJsonPath('actions.wait_href', null);
    }

    public function test_report_access_prefers_failed_submission_over_existing_ready_projection(): void
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_submission_first_access_stale_failed';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $submissionId = $this->createSubmission($attemptId, $anonId, 'failed', [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);
        $this->createResult($attemptId);
        $this->createProjection($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'unavailable',
            'reason_code' => 'submission_failed',
        ]);
        $response->assertJsonPath('payload.submission.id', $submissionId);
        $response->assertJsonPath('payload.result.error_code', 'SUBMISSION_JOB_TERMINAL_FAILURE');
        $response->assertJsonPath('actions.wait_href', null);
    }
}
