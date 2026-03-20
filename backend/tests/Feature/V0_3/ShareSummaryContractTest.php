<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\PersonalityProfile;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareSummaryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_share_returns_canonical_public_safe_summary_contract(): void
    {
        $this->seedScales();

        $anonId = 'anon_share_summary_contract';
        $attemptId = $this->createAttemptWithResult($anonId);
        $this->createPublicProfile('INTJ', 'zh-CN', 'INTJ - Architect', '独立、冷静、面向长期规划', '公开人格简介兜底文案');
        $token = $this->issueAnonToken($anonId);
        $this->grantShareAccess($attemptId, $anonId);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('id', $response->json('share_id'))
            ->assertJsonPath('scale_code', 'MBTI')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('title', 'INTJ - Architect')
            ->assertJsonPath('subtitle', '独立、冷静、面向长期规划')
            ->assertJsonPath('summary', '公开人格简介兜底文案')
            ->assertJsonPath('type_code', 'INTJ-A')
            ->assertJsonPath('type_name', '建筑师型')
            ->assertJsonPath('tagline', '冷静的长期规划者')
            ->assertJsonPath('rarity', '约 2%')
            ->assertJsonPath('tags.0', '战略')
            ->assertJsonPath('primary_cta_label', '开始测试')
            ->assertJsonPath('primary_cta_path', '/zh/tests/mbti-personality-test-16-personality-types')
            ->assertJsonMissingPath('report')
            ->assertJsonMissingPath('result')
            ->assertJsonMissingPath('offers')
            ->assertJsonMissingPath('recommended_reads')
            ->assertJsonMissingPath('layers')
            ->assertJsonMissingPath('sections')
            ->assertJsonMissingPath('cta');

        $this->assertCount(5, (array) $response->json('dimensions'));
        $this->assertSame('EI', $response->json('dimensions.0.id'));
        $this->assertSame('I', $response->json('dimensions.0.side'));
        $this->assertSame(65, $response->json('dimensions.0.pct'));
        $this->assertSame('clear', $response->json('dimensions.0.state'));
        $this->assertStableMbtiPublicSummaryV1((array) $response->json('mbti_public_summary_v1'), 'INTJ-A', 'INTJ', 'A');
        $this->assertSame('INTJ-A', $response->json('mbti_public_projection_v1.display_type'));
        $this->assertSame('INTJ', $response->json('mbti_public_projection_v1.canonical_type_code'));
        $this->assertSame('建筑师型', $response->json('mbti_public_projection_v1.profile.type_name'));
        $this->assertSame('公开人格简介兜底文案', $response->json('mbti_public_projection_v1.summary_card.summary'));
        $this->assertSame('growth.next_actions', $response->json('mbti_continuity_v1.carryover_focus_key'));
        $this->assertSame('unlock_to_continue_focus', $response->json('mbti_continuity_v1.carryover_reason'));
        $this->assertSame(['growth.next_actions', 'traits.close_call_axes', 'traits.adjacent_type_contrast'], $response->json('mbti_continuity_v1.recommended_resume_keys'));
        $this->assertSame($response->json('type_code'), $response->json('mbti_public_projection_v1.display_type'));
        $this->assertSame($response->json('type_name'), $response->json('mbti_public_projection_v1.profile.type_name'));
        $this->assertSame($response->json('dimensions'), $response->json('mbti_public_projection_v1.dimensions'));
        $this->assertStringContainsString('/share/'.$response->json('share_id'), (string) $response->json('share_url'));
        $this->assertStringNotContainsString('PRIVATE_PAID_SECTION_BODY', (string) $response->getContent());
        $this->assertStringNotContainsString('PRIVATE_RESULT_PATH', (string) $response->getContent());
        $this->assertStringNotContainsString('PRIVATE_RECOMMENDED_READ', (string) $response->getContent());
    }

    public function test_public_share_view_matches_summary_contract_without_private_payload_leaks(): void
    {
        $this->seedScales();

        $anonId = 'anon_share_view_contract';
        $attemptId = $this->createAttemptWithResult($anonId);
        $this->createPublicProfile('INTJ', 'zh-CN', 'INTJ - Architect', '独立、冷静、面向长期规划', '公开人格简介兜底文案');
        $token = $this->issueAnonToken($anonId);
        $this->grantShareAccess($attemptId, $anonId);

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
            ->assertJsonPath('share_id', $shareId)
            ->assertJsonPath('id', $shareId)
            ->assertJsonPath('title', 'INTJ - Architect')
            ->assertJsonPath('subtitle', '独立、冷静、面向长期规划')
            ->assertJsonPath('summary', '公开人格简介兜底文案')
            ->assertJsonPath('type_code', 'INTJ-A')
            ->assertJsonPath('type_name', '建筑师型')
            ->assertJsonPath('tagline', '冷静的长期规划者')
            ->assertJsonPath('rarity', '约 2%')
            ->assertJsonPath('primary_cta_label', '开始测试')
            ->assertJsonPath('primary_cta_path', '/zh/tests/mbti-personality-test-16-personality-types')
            ->assertJsonMissingPath('report')
            ->assertJsonMissingPath('result')
            ->assertJsonMissingPath('offers')
            ->assertJsonMissingPath('recommended_reads')
            ->assertJsonMissingPath('layers')
            ->assertJsonMissingPath('sections')
            ->assertJsonMissingPath('cta');

        foreach ([
            'share_url',
            'scale_code',
            'locale',
            'title',
            'subtitle',
            'summary',
            'type_code',
            'type_name',
            'tagline',
            'rarity',
            'tags',
            'dimensions',
            'primary_cta_label',
            'primary_cta_path',
            'mbti_continuity_v1',
            'mbti_public_summary_v1',
            'mbti_public_projection_v1',
        ] as $key) {
            $this->assertSame($share->json($key), $view->json($key), "share field mismatch: {$key}");
        }

        $this->assertSame(
            'INTJ-A',
            $view->json('mbti_public_summary_v1.display_type')
        );
        $this->assertSame(
            $view->json('type_code'),
            $view->json('mbti_public_projection_v1.display_type')
        );
        $this->assertSame(
            $view->json('type_name'),
            $view->json('mbti_public_projection_v1.profile.type_name')
        );
        $this->assertSame(
            $view->json('dimensions'),
            $view->json('mbti_public_projection_v1.dimensions')
        );

        $this->assertStringNotContainsString('PRIVATE_PAID_SECTION_BODY', (string) $view->getContent());
        $this->assertStringNotContainsString('PRIVATE_RESULT_PATH', (string) $view->getContent());
        $this->assertStringNotContainsString('PRIVATE_RECOMMENDED_READ', (string) $view->getContent());
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

    private function grantShareAccess(string $attemptId, string $anonId): void
    {
        app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            null
        );
    }

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
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
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 13, 'b' => 7, 'neutral' => 0, 'sum' => -6, 'total' => 20],
                'SN' => ['a' => 6, 'b' => 14, 'neutral' => 0, 'sum' => 8, 'total' => 20],
                'TF' => ['a' => 14, 'b' => 6, 'neutral' => 0, 'sum' => -8, 'total' => 20],
                'JP' => ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => -4, 'total' => 20],
                'AT' => ['a' => 11, 'b' => 9, 'neutral' => 0, 'sum' => 2, 'total' => 20],
            ],
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
                'recommended_reads' => [
                    ['title' => 'PRIVATE_RECOMMENDED_READ'],
                ],
                'cta' => [
                    'primary_label' => 'PRIVATE_CTA',
                ],
                'layers' => [
                    'identity' => [
                        'body' => 'PRIVATE_PAID_SECTION_BODY',
                    ],
                ],
                'result_path' => 'PRIVATE_RESULT_PATH',
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

    private function createPublicProfile(
        string $typeCode,
        string $locale,
        string $title,
        string $subtitle,
        string $excerpt
    ): void {
        PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $title,
            'subtitle' => $subtitle,
            'excerpt' => $excerpt,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => 'v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function assertStableMbtiPublicSummaryV1(
        array $summary,
        string $expectedRuntimeTypeCode,
        string $expectedCanonicalType,
        ?string $expectedVariant
    ): void {
        $this->assertSame($expectedRuntimeTypeCode, $summary['runtime_type_code'] ?? null);
        $this->assertSame($expectedCanonicalType, $summary['canonical_type_16'] ?? null);
        $this->assertSame($expectedRuntimeTypeCode, $summary['display_type'] ?? null);
        $this->assertSame($expectedVariant, $summary['variant'] ?? null);
        $this->assertSame('建筑师型', data_get($summary, 'profile.type_name'));
        $this->assertSame('冷静的长期规划者', data_get($summary, 'profile.nickname'));
        $this->assertSame('Public-safe share summary.', data_get($summary, 'summary_card.share_text'));
        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) ($summary['dimensions'] ?? [])
            )
        );
    }
}
