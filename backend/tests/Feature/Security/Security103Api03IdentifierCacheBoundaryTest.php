<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Security103Api03IdentifierCacheBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_read_json_responses_are_private_no_store(): void
    {
        $this->seedRuntime();

        $anonId = 'anon_security_103_api_03_attempt_read';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $headers = $this->authHeaders($anonId);

        foreach ([
            "/api/v0.3/attempts/{$attemptId}/result",
            "/api/v0.3/attempts/{$attemptId}/report",
            "/api/v0.3/attempts/{$attemptId}/report-access",
        ] as $uri) {
            $response = $this->withHeaders($headers)->getJson($uri);

            $response->assertOk();
            $this->assertPrivateNoStore($response);
        }
    }

    public function test_share_json_responses_are_no_store_and_public_view_omits_private_identifiers(): void
    {
        $this->seedRuntime();

        $anonId = 'anon_security_103_api_03_share';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $headers = $this->authHeaders($anonId);

        $ownerShare = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $ownerShare->assertOk();
        $this->assertPrivateNoStore($ownerShare);

        $shareId = (string) $ownerShare->json('share_id');
        $this->assertNotSame('', $shareId);

        $publicShare = $this->getJson("/api/v0.3/shares/{$shareId}");
        $publicShare->assertOk();
        $this->assertPrivateNoStore($publicShare);

        foreach ([
            'anon_id',
            'user_id',
            'fm_user_id',
            'fm_token',
            'private_result_path',
            'private_report_path',
            'audit_context',
            'staging_context',
        ] as $forbiddenPath) {
            $publicShare->assertJsonMissingPath($forbiddenPath);
        }
    }

    private function seedRuntime(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(string $anonId): array
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

        return [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];
    }

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => 'INTJ-A',
                'type_name' => '建筑师型',
                'summary' => 'Public-safe share summary.',
                'tagline' => '冷静的长期规划者',
                'rarity' => '约 2%',
                'keywords' => ['战略', '独立', '前瞻'],
            ],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function assertPrivateNoStore(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('Expires', '0');
    }
}
