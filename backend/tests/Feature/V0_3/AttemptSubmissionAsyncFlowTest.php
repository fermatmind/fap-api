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
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
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
            'answers' => [
                ['question_id' => 'SS-001', 'code' => '5'],
                ['question_id' => 'SS-002', 'code' => '4'],
                ['question_id' => 'SS-003', 'code' => '3'],
                ['question_id' => 'SS-004', 'code' => '2'],
                ['question_id' => 'SS-005', 'code' => '1'],
            ],
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
}

