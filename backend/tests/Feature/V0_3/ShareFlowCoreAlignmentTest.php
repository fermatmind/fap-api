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

final class ShareFlowCoreAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_share_get_and_post_return_aligned_payloads(): void
    {
        $this->seedScales();

        $anonId = 'anon_share_alignment_get_post';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];

        $get = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $post = $this->withHeaders($headers)->postJson("/api/v0.3/attempts/{$attemptId}/share");

        $get->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('compare_enabled', true)
            ->assertJsonPath('compare_cta_label', '邀请朋友来测并对比')
            ->assertJsonPath('primary_cta_path', '/zh/tests/mbti-personality-test-16-personality-types');
        $post->assertOk()
            ->assertJsonPath('ok', true);

        foreach ([
            'share_id',
            'share_url',
            'attempt_id',
            'org_id',
            'content_package_version',
            'type_code',
            'type_name',
            'id',
            'scale_code',
            'locale',
            'title',
            'subtitle',
            'summary',
            'tagline',
            'rarity',
            'tags',
            'dimensions',
            'primary_cta_label',
            'primary_cta_path',
            'compare_enabled',
            'compare_cta_label',
        ] as $field) {
            $this->assertSame($get->json($field), $post->json($field), "share field mismatch for {$field}");
        }

        $this->assertStringContainsString('/zh/share/'.$get->json('share_id'), (string) $get->json('share_url'));
    }

    public function test_attempt_share_and_public_share_view_return_aligned_summary_payloads(): void
    {
        $this->seedScales();

        $anonId = 'anon_share_alignment_view';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        $share = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $share->assertOk();
        $shareId = (string) $share->json('share_id');
        $this->assertNotSame('', $shareId);

        $view = $this->getJson("/api/v0.3/shares/{$shareId}");
        $view->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('compare_enabled', true)
            ->assertJsonPath('compare_cta_label', '邀请朋友来测并对比');

        foreach ([
            'share_id',
            'share_url',
            'attempt_id',
            'org_id',
            'content_package_version',
            'type_code',
            'type_name',
            'id',
            'scale_code',
            'locale',
            'title',
            'subtitle',
            'summary',
            'tagline',
            'rarity',
            'tags',
            'dimensions',
            'primary_cta_label',
            'primary_cta_path',
            'compare_enabled',
            'compare_cta_label',
        ] as $field) {
            $this->assertSame($share->json($field), $view->json($field), "share/view field mismatch for {$field}");
        }

        foreach ([
            'report',
            'result',
            'offers',
            'recommended_reads',
            'layers',
            'sections',
            'cta',
            'report_url',
            'report_pdf_url',
            'private_result_path',
        ] as $forbiddenField) {
            $view->assertJsonMissingPath($forbiddenField);
        }
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

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['total' => 100],
            'scores_pct' => [
                'EI' => 35,
                'SN' => 72,
                'TF' => 68,
                'JP' => 63,
                'AT' => 58,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
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
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
