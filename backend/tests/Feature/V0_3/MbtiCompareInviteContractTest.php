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

final class MbtiCompareInviteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_compare_invite_returns_pending_contract_and_new_invite_id_each_time(): void
    {
        $this->seedScales();

        [$attemptId, $anonId, $token] = $this->createOwnerContext();
        $shareId = $this->createShareViaApi($attemptId, $anonId, $token);

        $first = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'landing_path' => '/zh/share/'.$shareId,
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'mbti_compare',
            'meta' => [
                'share_click_id' => 'clk_001',
            ],
        ]);

        $second = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'utm_source' => 'share',
        ]);

        $first->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('share_id', $shareId)
            ->assertJsonPath('scale_code', 'MBTI')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('take_path', '/zh/tests/mbti-personality-test-16-personality-types/take?share_id='.$shareId.'&compare_invite_id='.$first->json('invite_id'))
            ->assertJsonPath('compare_path', '/zh/compare/mbti/'.$first->json('invite_id'))
            ->assertJsonPath('inviter.share_id', $shareId)
            ->assertJsonPath('inviter.type_code', 'INTJ-A')
            ->assertJsonPath('inviter.summary', 'Public-safe share summary.');

        $second->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('share_id', $shareId);

        $this->assertNotSame((string) $first->json('invite_id'), (string) $second->json('invite_id'));
        $first->assertJsonMissingPath('report');
        $first->assertJsonMissingPath('offers');
        $first->assertJsonMissingPath('sections');
        $first->assertJsonMissingPath('layers');
    }

    public function test_public_compare_pending_contract_is_public_safe(): void
    {
        $this->seedScales();

        [$attemptId, $anonId, $token] = $this->createOwnerContext();
        $shareId = $this->createShareViaApi($attemptId, $anonId, $token);
        $invite = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'mbti_compare',
        ]);
        $invite->assertOk();

        $inviteId = (string) $invite->json('invite_id');
        $response = $this->getJson("/api/v0.3/compare/mbti/{$inviteId}");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('invite_id', $inviteId)
            ->assertJsonPath('share_id', $shareId)
            ->assertJsonPath('scale_code', 'MBTI')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('primary_cta_label', '开始测试')
            ->assertJsonPath('primary_cta_path', '/zh/tests/mbti-personality-test-16-personality-types/take?share_id='.$shareId.'&compare_invite_id='.$inviteId)
            ->assertJsonPath('inviter.share_id', $shareId)
            ->assertJsonPath('inviter.type_code', 'INTJ-A')
            ->assertJsonPath('inviter.summary', 'Public-safe share summary.')
            ->assertJsonPath('inviter.mbti_public_summary_v1.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('inviter.mbti_public_projection_v1.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('invitee.mbti_public_summary_v1.runtime_type_code', null)
            ->assertJsonPath('invitee.mbti_public_projection_v1.runtime_type_code', null)
            ->assertJsonPath('compare.mbti_public_summary_v1.runtime_type_code', null)
            ->assertJsonMissingPath('compare.mbti_public_projection_v1');

        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) $response->json('inviter.mbti_public_summary_v1.dimensions')
            )
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $response->json('inviter.mbti_public_projection_v1'),
            'INTJ-A',
            'INTJ',
            'INTJ-A',
            'A',
            ['EI', 'SN', 'TF', 'JP', 'AT']
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $response->json('invitee.mbti_public_projection_v1'),
            null,
            null,
            null,
            null
        );
        $this->assertSame([], (array) $response->json('invitee.mbti_public_projection_v1.dimensions'));
        $this->assertSame([], (array) $response->json('invitee.mbti_public_projection_v1.sections'));
        $this->assertSame([], (array) $response->json('invitee.mbti_public_projection_v1.seo.jsonld'));
        $this->assertSame('compare.pending_scaffold', $response->json('invitee.mbti_public_projection_v1._meta.authority_source'));

        foreach ([
            'report',
            'offers',
            'recommended_reads',
            'cta',
            'sections',
            'layers',
            'pdf',
            'order',
            'reward',
            'coupon',
        ] as $forbiddenField) {
            $response->assertJsonMissingPath($forbiddenField);
        }

        $this->assertStringNotContainsString('PRIVATE_PAID_SECTION_BODY', (string) $response->getContent());
    }

    public function test_ready_compare_contract_keeps_nested_public_summary_v1_for_inviter_invitee_and_compare(): void
    {
        $this->seedScales();

        [$attemptId, $anonId, $token] = $this->createOwnerContext();
        $shareId = $this->createShareViaApi($attemptId, $anonId, $token);
        $invite = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'utm_source' => 'share',
        ]);
        $invite->assertOk();

        $inviteId = (string) $invite->json('invite_id');
        $inviteeAttemptId = $this->createMbtiAttemptWithResult('anon_compare_invitee', 'ENFP-T');

        DB::table('mbti_compare_invites')
            ->where('id', $inviteId)
            ->update([
                'invitee_attempt_id' => $inviteeAttemptId,
                'status' => 'ready',
                'accepted_at' => now(),
                'completed_at' => now(),
            ]);

        $response = $this->getJson("/api/v0.3/compare/mbti/{$inviteId}");

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('inviter.mbti_public_summary_v1.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('inviter.mbti_public_summary_v1.variant', 'A')
            ->assertJsonPath('inviter.mbti_public_projection_v1.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('invitee.mbti_public_summary_v1.runtime_type_code', 'ENFP-T')
            ->assertJsonPath('invitee.mbti_public_summary_v1.canonical_type_16', 'ENFP')
            ->assertJsonPath('invitee.mbti_public_summary_v1.variant', 'T')
            ->assertJsonPath('invitee.mbti_public_projection_v1.runtime_type_code', 'ENFP-T')
            ->assertJsonPath('invitee.mbti_public_projection_v1.canonical_type_code', 'ENFP')
            ->assertJsonPath('compare.mbti_public_summary_v1.runtime_type_code', null)
            ->assertJsonPath('compare.mbti_public_summary_v1.summary_card.title', $response->json('compare.title'))
            ->assertJsonPath('compare.mbti_public_summary_v1.summary_card.share_text', $response->json('compare.summary'))
            ->assertJsonMissingPath('compare.mbti_public_projection_v1');

        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) $response->json('compare.mbti_public_summary_v1.dimensions')
            )
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $response->json('inviter.mbti_public_projection_v1'),
            'INTJ-A',
            'INTJ',
            'INTJ-A',
            'A',
            ['EI', 'SN', 'TF', 'JP', 'AT']
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $response->json('invitee.mbti_public_projection_v1'),
            'ENFP-T',
            'ENFP',
            'ENFP-T',
            'T',
            ['EI', 'SN', 'TF', 'JP', 'AT']
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOwnerContext(): array
    {
        $anonId = 'anon_compare_invite_owner';
        $attemptId = $this->createMbtiAttemptWithResult($anonId, 'INTJ-A');
        $token = $this->issueAnonToken($anonId);

        return [$attemptId, $anonId, $token];
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

    private function createShareViaApi(string $attemptId, string $anonId, string $token): string
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk();

        return (string) $response->json('share_id');
    }

    private function createMbtiAttemptWithResult(string $anonId, string $typeCode = 'INTJ-A'): string
    {
        $attemptId = (string) Str::uuid();
        $resultProfile = $typeCode === 'ENFP-T'
            ? [
                'type_name' => '竞选者型',
                'summary' => 'Invitee public-safe share summary.',
                'tagline' => '热情的连接者',
                'rarity' => '约 8%',
                'keywords' => ['热情', '灵感', '共情'],
                'scores_pct' => ['EI' => 72, 'SN' => 74, 'TF' => 41, 'JP' => 38, 'AT' => 43],
            ]
            : [
                'type_name' => '建筑师型',
                'summary' => 'Public-safe share summary.',
                'tagline' => '冷静的长期规划者',
                'rarity' => '约 2%',
                'keywords' => ['战略', '独立', '前瞻'],
                'scores_pct' => ['EI' => 35, 'SN' => 72, 'TF' => 68, 'JP' => 63, 'AT' => 58],
            ];

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
            'type_code' => $typeCode,
            'scores_json' => ['total' => 100],
            'scores_pct' => $resultProfile['scores_pct'],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => $typeCode,
                'type_name' => $resultProfile['type_name'],
                'summary' => $resultProfile['summary'],
                'tagline' => $resultProfile['tagline'],
                'rarity' => $resultProfile['rarity'],
                'keywords' => $resultProfile['keywords'],
                'layers' => [
                    'identity' => [
                        'body' => 'PRIVATE_PAID_SECTION_BODY',
                    ],
                ],
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

    /**
     * @param  array<string, mixed>  $projection
     * @param  list<string>  $expectedDimensionIds
     */
    private function assertStableMbtiPublicProjectionV1(
        array $projection,
        ?string $expectedRuntimeTypeCode,
        ?string $expectedCanonicalType,
        ?string $expectedDisplayType,
        ?string $expectedVariant,
        array $expectedDimensionIds = []
    ): void {
        foreach ([
            'runtime_type_code',
            'canonical_type_code',
            'display_type',
            'variant_code',
            'profile',
            'summary_card',
            'dimensions',
            'sections',
            'seo',
            'offer_set',
            '_meta',
        ] as $key) {
            $this->assertArrayHasKey($key, $projection);
        }

        $this->assertSame($expectedRuntimeTypeCode, $projection['runtime_type_code'] ?? null);
        $this->assertSame($expectedCanonicalType, $projection['canonical_type_code'] ?? null);
        $this->assertSame($expectedDisplayType, $projection['display_type'] ?? null);
        $this->assertSame($expectedVariant, $projection['variant_code'] ?? null);
        $this->assertIsArray($projection['profile'] ?? null);
        $this->assertIsArray($projection['summary_card'] ?? null);
        $this->assertIsArray($projection['dimensions'] ?? null);
        $this->assertIsArray($projection['sections'] ?? null);
        $this->assertIsArray($projection['seo'] ?? null);
        $this->assertIsArray($projection['offer_set'] ?? null);
        $this->assertIsArray($projection['_meta'] ?? null);
        $this->assertSame(
            $expectedDimensionIds,
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) ($projection['dimensions'] ?? [])
            )
        );
    }
}
