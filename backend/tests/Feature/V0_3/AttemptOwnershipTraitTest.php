<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptOwnershipTraitTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function seedAttemptAndResult(string $anonId, string $scaleCode = 'MBTI'): string
    {
        $attemptId = (string) Str::uuid();
        $isMbti = $scaleCode === 'MBTI';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $isMbti ? 144 : 5,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? 'MBTI-CN-v0.3' : "{$scaleCode}.dir",
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $isMbti ? 'INTJ-A' : 'SIMPLE-SCORE',
            'scores_json' => $isMbti
                ? [
                    'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                    'SN' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                    'TF' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                    'JP' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                    'AT' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                ]
                : ['raw_score' => 15, 'final_score' => 15],
            'scores_pct' => $isMbti
                ? [
                    'EI' => 50,
                    'SN' => 50,
                    'TF' => 50,
                    'JP' => 50,
                    'AT' => 50,
                ]
                : [],
            'axis_states' => $isMbti
                ? [
                    'EI' => 'clear',
                    'SN' => 'clear',
                    'TF' => 'clear',
                    'JP' => 'clear',
                    'AT' => 'clear',
                ]
                : [],
            'content_package_version' => 'v0.3',
            'result_json' => $isMbti
                ? [
                    'type_code' => 'INTJ-A',
                    'scores_json' => [
                        'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                    ],
                ]
                : [
                    'raw_score' => 15,
                    'final_score' => 15,
                    'type_code' => 'SIMPLE-SCORE',
                ],
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? 'MBTI-CN-v0.3' : "{$scaleCode}.dir",
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
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
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    public function test_report_returns_404_without_auth_and_anon_header(): void
    {
        $this->seedScales();
        $attemptId = $this->seedAttemptAndResult('sec003-owner-anon', 'SIMPLE_SCORE_DEMO');

        $this->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]))
            ->assertStatus(404);
    }

    public function test_report_returns_404_when_anon_header_mismatch(): void
    {
        $this->seedScales();
        $attemptId = $this->seedAttemptAndResult('sec003-owner-anon', 'SIMPLE_SCORE_DEMO');

        $this->withHeader('X-Anon-Id', 'sec003-other-anon')
            ->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]))
            ->assertStatus(404);
    }

    public function test_report_with_matching_anon_header_never_500(): void
    {
        $this->seedScales();
        $anonId = 'sec003-owner-anon';
        $attemptId = $this->seedAttemptAndResult($anonId);
        $token = $this->issueAnonToken($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])
            ->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]));

        $this->assertContains($response->status(), [200, 402]);

        if ($response->status() === 200) {
            $this->assertIsBool($response->json('locked'));
        }
    }
}
