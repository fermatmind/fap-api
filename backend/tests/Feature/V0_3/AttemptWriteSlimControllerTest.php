<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptWriteSlimControllerTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
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

    public function test_start_returns_attempt_id_and_question_count(): void
    {
        $this->seedScales();

        $start = $this->withHeaders([
            'X-Anon-Id' => 'slim-start-anon',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ]);

        $start->assertStatus(200);
        $this->assertNotSame('', (string) $start->json('attempt_id'));
        $this->assertIsInt($start->json('question_count'));
        $this->assertGreaterThan(0, (int) $start->json('question_count'));
    }

    public function test_submit_returns_result_and_report_structure(): void
    {
        $this->seedScales();

        $anonId = 'slim-submit-anon';
        $anonToken = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
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

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $this->assertIsArray($submit->json('result'));
        $this->assertIsArray($submit->json('report'));
        $this->assertIsBool($submit->json('report.locked'));
        $this->assertIsString($submit->json('report.access_level'));
    }
}
