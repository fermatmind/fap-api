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

final class AttemptPublicReportReadHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
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

    private function createAttempt(string $attemptId, string $scaleCode, string $anonId): void
    {
        $isMbti = $scaleCode === 'MBTI';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $isMbti ? 144 : 20,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? 'MBTI-CN-v0.3' : "{$scaleCode}.dir",
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createResult(string $attemptId, string $scaleCode): void
    {
        $isMbti = $scaleCode === 'MBTI';

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $isMbti ? 'INTJ-A' : 'SDS-READY',
            'scores_json' => $isMbti
                ? [
                    'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                ]
                : ['total' => 42],
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
            'content_package_version' => 'result-v1',
            'result_json' => $isMbti
                ? [
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
                ]
                : [
                    'scale_code' => 'SDS_20',
                    'scores' => ['total' => 42],
                    'quality' => ['level' => 'ok'],
                    'report_tags' => [],
                    'sections' => [],
                ],
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? 'MBTI-CN-v0.3' : "{$scaleCode}.dir",
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_public_mbti_report_can_be_read_without_attempt_ownership(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_owner';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locked', true);
        $response->assertJsonPath('variant', 'free');
        $response->assertJsonPath('meta.scale_code_legacy', 'MBTI');
        $this->assertIsArray($response->json('report'));
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }

    public function test_public_mbti_report_rejects_orphan_result_with_explicit_attempt_not_found_contract(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeader('X-Anon-Id', 'anon_orphan_probe')
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }

    public function test_sds20_report_still_requires_existing_ownership_chain(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'SDS_20', 'anon_sds_owner');
        $this->createResult($attemptId, 'SDS_20');

        $response = $this->withHeader('X-Anon-Id', 'anon_sds_probe')
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }
}
