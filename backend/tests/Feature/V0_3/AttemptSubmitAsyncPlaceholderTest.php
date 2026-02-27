<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSubmitAsyncPlaceholderTest extends TestCase
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

    public function test_report_returns_generating_placeholder_when_async_submission_is_pending(): void
    {
        config()->set('fap.features.submit_async_v2', true);
        $this->seedScales();

        $anonId = 'anon_async_report_placeholder';
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
        $submissionId = (string) $submit->json('submission_id');
        $this->assertNotSame('', $submissionId);

        $report = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $report->assertStatus(202);
        $report->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => true,
            'submission_state' => 'pending',
            'result' => null,
            'report' => [],
        ]);
        $this->assertSame($submissionId, (string) data_get($report->json(), 'submission.id', ''));
    }
}
