<?php

namespace Tests\Feature\V0_3;

use App\Jobs\ProcessAttemptSubmissionJob;
use App\Services\Attempts\AttemptSubmissionService;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSubmissionAsyncFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int,array<string,mixed>>
     */
    private function demoAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

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

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr17SimpleScoreDemoSeeder)->run();
    }

    public function test_submit_async_ack_and_status_endpoint(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_async_submit_owner';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        Queue::fake();

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $submit->assertStatus(202);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'submission_state' => 'pending',
            'generating' => true,
            'mode' => 'async',
        ]);
        $submissionId = (string) $submit->json('submission_id');
        $this->assertNotSame('', $submissionId);

        Queue::assertPushed(ProcessAttemptSubmissionJob::class, function (ProcessAttemptSubmissionJob $job) use ($submissionId): bool {
            return $job->submissionId === $submissionId;
        });

        $pending = DB::table('attempt_submissions')->where('id', $submissionId)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->state ?? ''));
        $this->assertSame($attemptId, (string) ($pending->attempt_id ?? ''));

        $pendingStatus = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/submission');

        $pendingStatus->assertStatus(202);
        $pendingStatus->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => true,
            'submission' => [
                'id' => $submissionId,
                'state' => 'pending',
            ],
        ]);

        $job = new ProcessAttemptSubmissionJob($submissionId);
        $job->handle(app(AttemptSubmissionService::class));

        $done = DB::table('attempt_submissions')->where('id', $submissionId)->first();
        $this->assertNotNull($done);
        $this->assertSame('succeeded', (string) ($done->state ?? ''));
        $this->assertNotNull($done->response_payload_json ?? null);

        $status = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/submission');

        $status->assertStatus(200);
        $status->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => false,
            'submission' => [
                'id' => $submissionId,
                'state' => 'succeeded',
            ],
        ]);
        $this->assertTrue((bool) data_get($status->json(), 'result.ok'));
    }

    public function test_submit_async_reuses_pending_submission_without_requeueing(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_async_submit_pending_reuse';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        Queue::fake();

        $first = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $first->assertStatus(202);
        $submissionId = (string) $first->json('submission_id');

        $replay = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $replay->assertStatus(202);
        $replay->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'submission_id' => $submissionId,
            'submission_state' => 'pending',
            'generating' => true,
            'mode' => 'async',
            'idempotent' => true,
        ]);

        Queue::assertPushed(ProcessAttemptSubmissionJob::class, 1);
    }

    public function test_submit_async_retries_failed_submission_with_same_dedupe_key(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_async_submit_failed_retry';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        Queue::fake();

        $first = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $first->assertStatus(202);
        $submissionId = (string) $first->json('submission_id');

        DB::table('attempt_submissions')
            ->where('id', $submissionId)
            ->update([
                'state' => 'failed',
                'error_code' => 'SUBMISSION_QUEUE_DISPATCH_FAILED',
                'error_message' => 'dispatch failed',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $retry = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $retry->assertStatus(202);
        $retry->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'submission_id' => $submissionId,
            'submission_state' => 'pending',
            'generating' => true,
            'mode' => 'async',
            'idempotent' => false,
        ]);

        $reset = DB::table('attempt_submissions')->where('id', $submissionId)->first();
        $this->assertNotNull($reset);
        $this->assertSame('pending', (string) ($reset->state ?? ''));
        $this->assertNull($reset->error_code ?? null);
        $this->assertNull($reset->error_message ?? null);
        $this->assertNull($reset->finished_at ?? null);

        Queue::assertPushed(ProcessAttemptSubmissionJob::class, 2);
    }

    public function test_submit_async_replays_stored_result_when_submission_already_succeeded(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_async_submit_succeeded_reuse';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        Queue::fake();

        $first = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $first->assertStatus(202);
        $submissionId = (string) $first->json('submission_id');

        $job = new ProcessAttemptSubmissionJob($submissionId);
        $job->handle(app(AttemptSubmissionService::class));

        $replay = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->demoAnswers(),
            'duration_ms' => 120000,
        ]);

        $replay->assertStatus(200);
        $replay->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'idempotent' => true,
            'submission_id' => $submissionId,
            'submission_state' => 'succeeded',
            'mode' => 'async',
        ]);

        Queue::assertPushed(ProcessAttemptSubmissionJob::class, 1);
        $this->assertSame(1, DB::table('results')->where('attempt_id', $attemptId)->count());
    }
}
