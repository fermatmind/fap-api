<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptSubmissionRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createAttempt(string $attemptId, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(9),
            'pack_id' => 'MBTI.pack',
            'dir_version' => 'MBTI.dir',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createSubmission(string $attemptId, string $anonId, string $state, int $updatedMinutesAgo = 1, ?array $responsePayload = null): string
    {
        $submissionId = (string) Str::uuid();

        \DB::table('attempt_submissions')->insert([
            'id' => $submissionId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => $anonId,
            'dedupe_key' => hash('sha256', $attemptId.':'.$state),
            'mode' => 'async',
            'state' => $state,
            'error_code' => $state === 'failed' ? 'SUBMISSION_JOB_TERMINAL_FAILURE' : null,
            'error_message' => $state === 'failed' ? 'submission job terminal failure.' : null,
            'request_payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => $responsePayload !== null ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'started_at' => now()->subMinutes($updatedMinutesAgo),
            'finished_at' => in_array($state, ['succeeded', 'failed'], true) ? now()->subMinutes($updatedMinutesAgo) : null,
            'created_at' => now()->subMinutes($updatedMinutesAgo + 1),
            'updated_at' => now()->subMinutes($updatedMinutesAgo),
        ]);

        return $submissionId;
    }

    private function createResult(string $attemptId): void
    {
        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'result-v1',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => 'MBTI.result-pack',
            'dir_version' => 'MBTI.result-dir',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now()->subMinutes(1),
        ]);
    }

    public function test_command_reports_repairable_submission_chain_findings(): void
    {
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.access_projection_dual_write_enabled', true);

        $stuckAttemptId = (string) Str::uuid();
        $missingResultAttemptId = (string) Str::uuid();
        $failedProjectionAttemptId = (string) Str::uuid();
        $projectionMissingAttemptId = (string) Str::uuid();

        $this->createAttempt($stuckAttemptId, 'anon-stuck');
        $this->createSubmission($stuckAttemptId, 'anon-stuck', 'pending', 25);

        $this->createAttempt($missingResultAttemptId, 'anon-missing-result');
        $this->createSubmission($missingResultAttemptId, 'anon-missing-result', 'succeeded', 3, [
            'ok' => true,
            'attempt_id' => $missingResultAttemptId,
        ]);

        $this->createAttempt($failedProjectionAttemptId, 'anon-failed');
        $this->createSubmission($failedProjectionAttemptId, 'anon-failed', 'failed', 2, [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'terminal_failure' => true,
        ]);
        UnifiedAccessProjection::query()->create([
            'attempt_id' => $failedProjectionAttemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'stale_ready',
            'projection_version' => 1,
            'actions_json' => ['report' => true, 'pdf' => true],
            'payload_json' => ['attempt_id' => $failedProjectionAttemptId],
            'produced_at' => now()->subMinutes(2),
            'refreshed_at' => now()->subMinutes(2),
        ]);

        $this->createAttempt($projectionMissingAttemptId, 'anon-projection-missing');
        $this->createSubmission($projectionMissingAttemptId, 'anon-projection-missing', 'succeeded', 1, [
            'ok' => true,
            'attempt_id' => $projectionMissingAttemptId,
        ]);
        $this->createResult($projectionMissingAttemptId);

        $exitCode = Artisan::call('ops:attempt-submission-recovery', [
            '--json' => 1,
            '--strict' => 0,
            '--window-hours' => 24,
            '--pending-timeout-minutes' => 15,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(5, (int) data_get($payload, 'summary.finding_total'));
        $this->assertSame(0, (int) data_get($payload, 'summary.repair_total'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.submission_stuck_pending'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.projection_stale_against_pending_submission'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.submission_succeeded_result_missing'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.projection_stale_against_failed_submission'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_issue_code.projection_missing_after_result'));
    }

    public function test_repair_mode_requeues_safe_cases_refreshes_projection_and_emits_alert(): void
    {
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.access_projection_dual_write_enabled', true);
        config()->set('ops.alert.webhook', 'https://alerts.example.test/ops');
        Http::fake(['https://alerts.example.test/*' => Http::response(['ok' => true], 200)]);
        Queue::fake();

        $stuckAttemptId = (string) Str::uuid();
        $missingResultAttemptId = (string) Str::uuid();
        $failedProjectionAttemptId = (string) Str::uuid();
        $projectionMissingAttemptId = (string) Str::uuid();

        $this->createAttempt($stuckAttemptId, 'anon-stuck');
        $stuckSubmissionId = $this->createSubmission($stuckAttemptId, 'anon-stuck', 'running', 20);

        $this->createAttempt($missingResultAttemptId, 'anon-missing-result');
        $missingResultSubmissionId = $this->createSubmission($missingResultAttemptId, 'anon-missing-result', 'succeeded', 3, [
            'ok' => true,
            'attempt_id' => $missingResultAttemptId,
        ]);

        $this->createAttempt($failedProjectionAttemptId, 'anon-failed');
        $failedSubmissionId = $this->createSubmission($failedProjectionAttemptId, 'anon-failed', 'failed', 2, [
            'ok' => false,
            'error_code' => 'SUBMISSION_JOB_TERMINAL_FAILURE',
            'message' => 'submission job terminal failure.',
            'terminal_failure' => true,
        ]);

        $this->createAttempt($projectionMissingAttemptId, 'anon-projection-missing');
        $this->createSubmission($projectionMissingAttemptId, 'anon-projection-missing', 'succeeded', 1, [
            'ok' => true,
            'attempt_id' => $projectionMissingAttemptId,
        ]);
        $this->createResult($projectionMissingAttemptId);

        $exitCode = Artisan::call('ops:attempt-submission-recovery', [
            '--json' => 1,
            '--strict' => 0,
            '--repair' => 1,
            '--window-hours' => 24,
            '--pending-timeout-minutes' => 15,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(5, (int) data_get($payload, 'summary.finding_total'));
        $repairCounts = is_array(data_get($payload, 'summary.by_repair_code')) ? data_get($payload, 'summary.by_repair_code') : [];
        $this->assertSame(array_sum(array_map('intval', $repairCounts)), (int) data_get($payload, 'summary.repair_total'));
        $this->assertSame(2, (int) data_get($payload, 'summary.by_repair_code.submission_requeued'));
        $this->assertGreaterThanOrEqual(3, (int) data_get($payload, 'summary.by_repair_code.projection_refreshed'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_repair_code.projection_backfilled'));

        Queue::assertPushed(\App\Jobs\ProcessAttemptSubmissionJob::class, 2);

        $stuck = \DB::table('attempt_submissions')->where('id', $stuckSubmissionId)->first();
        $this->assertSame('pending', (string) ($stuck->state ?? ''));
        $this->assertNull($stuck->finished_at ?? null);

        $missingResult = \DB::table('attempt_submissions')->where('id', $missingResultSubmissionId)->first();
        $this->assertSame('pending', (string) ($missingResult->state ?? ''));

        $failedProjection = UnifiedAccessProjection::query()->where('attempt_id', $failedProjectionAttemptId)->first();
        $this->assertNotNull($failedProjection);
        $this->assertSame('locked', (string) $failedProjection->access_state);
        $this->assertSame('unavailable', (string) $failedProjection->report_state);
        $this->assertSame('submission_failed', (string) $failedProjection->reason_code);

        $backfilledProjection = UnifiedAccessProjection::query()->where('attempt_id', $projectionMissingAttemptId)->first();
        $this->assertNotNull($backfilledProjection);

        Http::assertSentCount(1);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://alerts.example.test/ops'
                && str_contains((string) $request->body(), 'ops:attempt-submission-recovery');
        });
    }
}
