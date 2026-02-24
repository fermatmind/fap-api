<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60StartSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_start_submit_returns_dimension_scores(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_eq60_owner';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'EQ_60',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
        ]);

        $start->assertStatus(200);
        $start->assertJsonPath('scale_code', 'EQ_60');
        $start->assertJsonPath('question_count', 60);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 160000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);

        $dimScores = (array) data_get($submit->json(), 'result.breakdown_json.dim_scores', []);
        $this->assertSame(45, (int) ($dimScores['SA'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['ER'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['SE'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['RM'] ?? 0));
        $this->assertSame(180, (int) data_get($submit->json(), 'result.final_score', 0));
    }

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
}
