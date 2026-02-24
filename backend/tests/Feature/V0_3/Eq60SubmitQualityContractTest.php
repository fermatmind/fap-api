<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60SubmitQualityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_returns_quality_norms_scores_and_version_snapshot_contract(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_eq60_contract';
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
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 65000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);
        $submit->assertJsonPath('result.scale_code', 'EQ_60');
        $submit->assertJsonPath('result.quality.level', 'D');
        $submit->assertJsonPath('result.norms.status', 'PROVISIONAL');
        $submit->assertJsonPath('result.norms.version', 'bootstrap_v1');
        $submit->assertJsonPath('result.scores.SA.raw_sum', 45);
        $submit->assertJsonPath('result.scores.ER.raw_sum', 45);
        $submit->assertJsonPath('result.scores.EM.raw_sum', 45);
        $submit->assertJsonPath('result.scores.RM.raw_sum', 45);
        $submit->assertJsonPath('result.version_snapshot.engine_version', 'v1.0_normed_validity');
        $submit->assertJsonPath('result.version_snapshot.scoring_spec_version', 'eq60_spec_2026_v2');
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
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
