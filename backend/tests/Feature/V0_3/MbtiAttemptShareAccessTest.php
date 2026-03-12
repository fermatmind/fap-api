<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\Share;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiAttemptShareAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_mbti_owner_can_create_share_via_get_and_post(): void
    {
        $this->seedScales();

        $anonId = 'anon_mbti_share_owner';
        $attemptId = $this->createAttemptWithResult('MBTI', $anonId);
        $token = $this->issueAnonToken($anonId);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];

        $get = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $post = $this->withHeaders($headers)->postJson("/api/v0.3/attempts/{$attemptId}/share");

        $get->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('compare_enabled', true);
        $post->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('share_id', $get->json('share_id'));
    }

    public function test_non_owner_cannot_create_mbti_share(): void
    {
        $this->seedScales();

        $ownerAnonId = 'anon_mbti_share_owner_2';
        $probeAnonId = 'anon_mbti_share_probe';
        $attemptId = $this->createAttemptWithResult('MBTI', $ownerAnonId);
        $token = $this->issueAnonToken($probeAnonId);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $probeAnonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertStatus(404);
        $response->assertJsonPath('ok', false);
    }

    public function test_non_mbti_share_returns_not_found_for_compare_invite_contract(): void
    {
        $this->seedScales();

        $attemptId = $this->createAttemptWithResult('SDS_20', 'anon_non_mbti_owner');
        $share = new Share;
        $share->id = bin2hex(random_bytes(16));
        $share->attempt_id = $attemptId;
        $share->anon_id = 'anon_non_mbti_owner';
        $share->scale_code = 'SDS_20';
        $share->scale_version = 'v0.3';
        $share->content_package_version = 'v0.3';
        $share->save();

        $response = $this->postJson("/api/v0.3/shares/{$share->id}/compare-invites", [
            'anon_id' => 'anon_non_mbti_probe',
            'utm_source' => 'share',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('ok', false);
    }

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

    private function createAttemptWithResult(string $scaleCode, string $anonId): string
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
            'question_count' => $isMbti ? 144 : 20,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3') : 'SDS_20.pack',
            'dir_version' => $isMbti ? (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3') : 'SDS_20.dir',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $isMbti ? 'INTJ-A' : 'SDS-READY',
            'scores_json' => ['total' => 100],
            'scores_pct' => $isMbti ? [
                'EI' => 35,
                'SN' => 72,
                'TF' => 68,
                'JP' => 63,
                'AT' => 58,
            ] : [],
            'axis_states' => $isMbti ? [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ] : [],
            'content_package_version' => 'v0.3',
            'result_json' => $isMbti
                ? [
                    'type_code' => 'INTJ-A',
                    'type_name' => '建筑师型',
                    'summary' => 'Public-safe share summary.',
                    'tagline' => '冷静的长期规划者',
                    'rarity' => '约 2%',
                    'keywords' => ['战略', '独立', '前瞻'],
                ]
                : [
                    'type_code' => 'SDS-READY',
                    'type_name' => '抑郁自评',
                    'summary' => 'Non MBTI summary',
                ],
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3') : 'SDS_20.pack',
            'dir_version' => $isMbti ? (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3') : 'SDS_20.dir',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
