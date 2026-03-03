<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSubmissionSyncFlowTest extends TestCase
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

    public function test_sync_legacy_submit_records_succeeded_submission_and_status_endpoint_reads_it(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_sync_submit_owner';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit?mode=sync_legacy', [
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

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $row = DB::table('attempt_submissions')
            ->where('org_id', 0)
            ->where('attempt_id', $attemptId)
            ->orderByDesc('updated_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('sync', (string) ($row->mode ?? ''));
        $this->assertSame('succeeded', (string) ($row->state ?? ''));
        $this->assertNotNull($row->response_payload_json ?? null);

        $status = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/submission');

        $status->assertStatus(200);
        $status->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => false,
            'submission' => [
                'state' => 'succeeded',
                'mode' => 'sync',
            ],
        ]);
        $this->assertNotNull(data_get($status->json(), 'result'));
    }

    public function test_sync_legacy_success_is_returned_when_previous_async_submission_failed(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_sync_after_async_failed';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $stale = now()->subMinutes(10);
        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => $anonId,
            'dedupe_key' => hash('sha256', 'stale_failed_'.$attemptId),
            'mode' => 'async',
            'state' => 'failed',
            'error_code' => 'VALIDATION_FAILED',
            'error_message' => 'answers required.',
            'request_payload_json' => json_encode(['answers' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => null,
            'started_at' => $stale,
            'finished_at' => $stale,
            'created_at' => $stale,
            'updated_at' => $stale,
        ]);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit?mode=sync_legacy', [
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

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $status = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/submission');

        $status->assertStatus(200);
        $status->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => false,
            'submission' => [
                'state' => 'succeeded',
                'mode' => 'sync',
            ],
        ]);
    }
}
