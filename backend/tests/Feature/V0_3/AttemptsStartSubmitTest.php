<?php

namespace Tests\Feature\V0_3;

use App\Models\Result;
use Database\Seeders\Pr16IqRavenDemoSeeder;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptsStartSubmitTest extends TestCase
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
        (new ScaleRegistrySeeder())->run();
        (new Pr16IqRavenDemoSeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    public function test_simple_score_start_submit_result(): void
    {
        $this->seedScales();
        $anonId = 'v03_simple_score_owner';
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

        $answers = [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $this->assertSame(15, (int) $submit->json('result.raw_score'));
        $this->assertSame(15, (int) $submit->json('result.final_score'));

        $result = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $result->assertStatus(200);
        $this->assertSame(15, (int) $result->json('result.raw_score'));

        $dup = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ]);
        $dup->assertStatus(200);
        $dup->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'idempotent' => true,
        ]);

        $this->assertSame(1, Result::where('attempt_id', $attemptId)->count());
    }

    public function test_iq_raven_time_bonus(): void
    {
        $this->seedScales();
        $anonId = 'v03_iq_owner';
        $anonToken = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_RAVEN',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'RAVEN_DEMO_1', 'code' => 'B'],
            ],
            'duration_ms' => 20000,
        ]);

        $submit->assertStatus(200);
        $this->assertSame(3, (int) $submit->json('result.breakdown_json.time_bonus'));
        $this->assertSame(4, (int) $submit->json('result.final_score'));
    }

    public function test_mbti_report_locked_true_without_entitlement(): void
    {
        $this->seedScales();
        $attemptId = (string) Str::uuid();
        \App\Models\Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_test',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
        ]);

        \App\Models\Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.2.1-TEST',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $resultResp = $this->withHeaders([
            'X-Anon-Id' => 'anon_test',
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $resultResp->assertStatus(200);
        $resultResp->assertJsonPath('type_code', 'INTJ-A');
        $this->assertIsArray($resultResp->json('scores'));
        $this->assertSame(50, (int) $resultResp->json('scores_pct.EI'));
        $this->assertSame('INTJ-A', (string) $resultResp->json('result.type_code'));

        $report = $this->withHeaders([
            'X-Anon-Id' => 'anon_test',
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $report->assertStatus(200);
        $report->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
        ]);

        $this->assertNotNull($report->json('view_policy'));
        $this->assertNotNull($report->json('report'));
    }
}
