<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MbtiReportHttpContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
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

    private function createAttemptWithResult(string $anonId, string $typeCode = 'INTJ-A'): string
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
            'type_code' => $typeCode,
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => $typeCode],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_locked_free_mbti_report_http_contract_includes_mbti_only_fields(): void
    {
        $this->seedScales();

        $anonId = 'anon_mbti_http_locked';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId, 'ENFP-T');

        $resp = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $resp->assertStatus(200);
        $resp->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
            'variant' => 'free',
        ]);
        $this->assertStableMbtiEnvelope($resp);

        $cta = (array) $resp->json('cta');
        $this->assertTrue((bool) $cta['visible']);
        $this->assertSame('upsell', $cta['kind']);
        $this->assertSame('MBTI_REPORT_FULL', $cta['target_sku']);
        $this->assertNotSame('', trim((string) $cta['target_sku_effective']));
        $this->assertStableMbtiPublicSummaryV1(
            (array) $resp->json('mbti_public_summary_v1'),
            'ENFP-T',
            'ENFP',
            'T'
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $resp->json('mbti_public_projection_v1'),
            'ENFP-T',
            'ENFP',
            'T'
        );
    }

    public function test_unlocked_paid_mbti_report_http_contract_keeps_section_gate_semantics(): void
    {
        $this->seedScales();

        $anonId = 'anon_mbti_http_full';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId, 'INTJ-A');

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            null,
            'attempt',
            null,
            ['core_full', 'career', 'relationships']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $resp = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $resp->assertStatus(200);
        $resp->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);
        $this->assertStableMbtiEnvelope($resp);

        $cta = (array) $resp->json('cta');
        $this->assertFalse((bool) $cta['visible']);
        $this->assertSame('none', $cta['kind']);
        $this->assertContains('paid', $this->collectAccessLevels($resp->json('report')));
        $this->assertStableMbtiPublicSummaryV1(
            (array) $resp->json('mbti_public_summary_v1'),
            'INTJ-A',
            'INTJ',
            'A'
        );
        $this->assertStableMbtiPublicProjectionV1(
            (array) $resp->json('mbti_public_projection_v1'),
            'INTJ-A',
            'INTJ',
            'A'
        );
    }

    private function assertStableMbtiEnvelope(TestResponse $response): void
    {
        /** @var array<string,mixed> $payload */
        $payload = $response->json();

        foreach ([
            'ok',
            'generating',
            'snapshot_error',
            'retry_after_seconds',
            'locked',
            'access_level',
            'variant',
            'offers',
            'modules_allowed',
            'modules_offered',
            'modules_preview',
            'view_policy',
            'meta',
            'report',
            'mbti_public_summary_v1',
            'mbti_public_projection_v1',
            'scale_code',
            'scale_code_legacy',
            'scale_code_v2',
            'scale_uid',
            'cta',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertIsBool($payload['ok']);
        $this->assertIsBool($payload['generating']);
        $this->assertIsBool($payload['snapshot_error']);
        $this->assertTrue($payload['retry_after_seconds'] === null || is_int($payload['retry_after_seconds']));
        $this->assertIsBool($payload['locked']);
        $this->assertIsString($payload['access_level']);
        $this->assertIsString($payload['variant']);
        $this->assertIsArray($payload['offers']);
        $this->assertIsArray($payload['modules_allowed']);
        $this->assertIsArray($payload['modules_offered']);
        $this->assertIsArray($payload['modules_preview']);
        $this->assertIsArray($payload['view_policy']);
        $this->assertIsArray($payload['meta']);
        $this->assertIsArray($payload['report']);
        $this->assertIsArray($payload['mbti_public_summary_v1']);
        $this->assertIsArray($payload['mbti_public_projection_v1']);
        $this->assertNotSame('', trim((string) $payload['scale_code']));
        $this->assertNotSame('', trim((string) $payload['scale_code_legacy']));
        $this->assertNotSame('', trim((string) $payload['scale_code_v2']));
        $this->assertNotSame('', trim((string) $payload['scale_uid']));

        $this->assertStableCtaShape((array) $payload['cta']);
        $this->assertStableMbtiReportShape((array) $payload['report']);
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
        foreach ([
            'runtime_type_code',
            'canonical_type_16',
            'display_type',
            'variant',
            'profile',
            'summary_card',
            'dimensions',
            'sections',
            'offer_set',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        $this->assertSame($expectedRuntimeTypeCode, $summary['runtime_type_code']);
        $this->assertSame($expectedCanonicalType, $summary['canonical_type_16']);
        $this->assertSame($expectedRuntimeTypeCode, $summary['display_type']);
        $this->assertSame($expectedVariant, $summary['variant']);
        $this->assertIsArray($summary['profile']);
        $this->assertIsArray($summary['summary_card']);
        $this->assertIsArray($summary['dimensions']);
        $this->assertIsArray($summary['sections']);
        $this->assertIsArray($summary['offer_set']);

        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) $summary['dimensions']
            )
        );
        $this->assertSame($expectedRuntimeTypeCode, data_get($summary, 'display_type'));
        $this->assertNotNull(data_get($summary, 'summary_card.share_text'));
        $this->assertSame(
            data_get($summary, 'offer_set.upgrade_sku'),
            data_get($summary, 'offer_set.cta.target_sku_effective')
                ?? data_get($summary, 'offer_set.cta.target_sku')
                ?? data_get($summary, 'offer_set.upgrade_sku')
        );
    }

    /**
     * @param  array<string,mixed>  $projection
     */
    private function assertStableMbtiPublicProjectionV1(
        array $projection,
        string $expectedRuntimeTypeCode,
        string $expectedCanonicalType,
        ?string $expectedVariant
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
        $this->assertSame($expectedRuntimeTypeCode, $projection['display_type'] ?? null);
        $this->assertSame($expectedVariant, $projection['variant_code'] ?? null);
        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                (array) ($projection['dimensions'] ?? [])
            )
        );
    }

    /**
     * @param  array<string,mixed>  $cta
     */
    private function assertStableCtaShape(array $cta): void
    {
        foreach ([
            'visible',
            'kind',
            'title',
            'subtitle',
            'primary_label',
            'secondary_label',
            'benefit_bullets',
            'badge',
            'target_sku',
            'target_sku_effective',
        ] as $key) {
            $this->assertArrayHasKey($key, $cta);
        }

        $this->assertIsBool($cta['visible']);
        $this->assertIsString($cta['kind']);
        $this->assertTrue($cta['title'] === null || is_string($cta['title']));
        $this->assertTrue($cta['subtitle'] === null || is_string($cta['subtitle']));
        $this->assertTrue($cta['primary_label'] === null || is_string($cta['primary_label']));
        $this->assertTrue($cta['secondary_label'] === null || is_string($cta['secondary_label']));
        $this->assertIsArray($cta['benefit_bullets']);
        $this->assertTrue($cta['badge'] === null || is_string($cta['badge']));
        $this->assertTrue($cta['target_sku'] === null || is_string($cta['target_sku']));
        $this->assertTrue($cta['target_sku_effective'] === null || is_string($cta['target_sku_effective']));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function assertStableMbtiReportShape(array $report): void
    {
        foreach ([
            'profile',
            'identity_card',
            'highlights',
            'layers',
            'sections',
            'recommended_reads',
            'tags',
            'scores',
            'scores_pct',
            'axis_states',
        ] as $key) {
            $this->assertArrayHasKey($key, $report);
        }

        $this->assertStableProfileShape((array) $report['profile']);
        $this->assertStableIdentityCardShape((array) $report['identity_card']);
        $this->assertStableHighlightsShape((array) $report['highlights']);
        $this->assertStableLayersShape((array) $report['layers']);
        $this->assertStableSectionsShape((array) $report['sections']);
        $this->assertStableRecommendedReadsShape((array) $report['recommended_reads']);
        $this->assertIsArray($report['tags']);
        $this->assertIsArray($report['scores']);
        $this->assertIsArray($report['scores_pct']);
        $this->assertIsArray($report['axis_states']);
    }

    /**
     * @param  array<string,mixed>  $profile
     */
    private function assertStableProfileShape(array $profile): void
    {
        foreach ([
            'type_code',
            'type_name',
            'tagline',
            'rarity',
            'keywords',
            'short_summary',
        ] as $key) {
            $this->assertArrayHasKey($key, $profile);
        }

        $this->assertNotSame('', trim((string) $profile['type_code']));
        $this->assertNotSame('', trim((string) $profile['type_name']));
        $this->assertTrue($profile['tagline'] === null || is_string($profile['tagline']));
        $this->assertTrue($profile['rarity'] === null || is_string($profile['rarity']) || is_numeric($profile['rarity']));
        $this->assertIsArray($profile['keywords']);
        $this->assertTrue($profile['short_summary'] === null || is_string($profile['short_summary']));
    }

    /**
     * @param  array<string,mixed>  $identityCard
     */
    private function assertStableIdentityCardShape(array $identityCard): void
    {
        foreach ([
            'title',
            'subtitle',
            'tagline',
            'summary',
            'share_text',
            'tags',
            'badge',
        ] as $key) {
            $this->assertArrayHasKey($key, $identityCard);
        }

        $this->assertNotSame('', trim((string) $identityCard['title']));
        $this->assertTrue($identityCard['subtitle'] === null || is_string($identityCard['subtitle']));
        $this->assertTrue($identityCard['tagline'] === null || is_string($identityCard['tagline']));
        $this->assertTrue($identityCard['summary'] === null || is_string($identityCard['summary']));
        $this->assertTrue($identityCard['share_text'] === null || is_string($identityCard['share_text']));
        $this->assertIsArray($identityCard['tags']);
        $this->assertIsArray($identityCard['badge']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $highlights
     */
    private function assertStableHighlightsShape(array $highlights): void
    {
        $this->assertIsArray($highlights);
        $this->assertNotEmpty($highlights);

        $first = (array) ($highlights[0] ?? []);
        foreach (['title', 'text', 'tips'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $this->assertNotSame('', trim((string) $first['title']));
        $this->assertNotSame('', trim((string) $first['text']));
        $this->assertIsArray($first['tips']);
    }

    /**
     * @param  array<string,mixed>  $layers
     */
    private function assertStableLayersShape(array $layers): void
    {
        foreach (['role_card', 'strategy_card', 'identity'] as $key) {
            $this->assertArrayHasKey($key, $layers);
            $this->assertIsArray($layers[$key]);
        }

        $identity = (array) $layers['identity'];
        foreach (['title', 'subtitle', 'one_liner', 'bullets', 'tags'] as $key) {
            $this->assertArrayHasKey($key, $identity);
        }

        $this->assertNotSame('', trim((string) $identity['title']));
        $this->assertNotSame('', trim((string) $identity['subtitle']));
        $this->assertNotSame('', trim((string) $identity['one_liner']));
        $this->assertIsArray($identity['bullets']);
        $this->assertIsArray($identity['tags']);
    }

    /**
     * @param  array<string,mixed>  $sections
     */
    private function assertStableSectionsShape(array $sections): void
    {
        $this->assertSame(
            ['traits', 'career', 'growth', 'relationships'],
            array_keys($sections)
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $reads
     */
    private function assertStableRecommendedReadsShape(array $reads): void
    {
        $this->assertIsArray($reads);

        $first = (array) ($reads[0] ?? []);
        if ($first === []) {
            return;
        }

        foreach ([
            'id',
            'type',
            'title',
            'desc',
            'url',
            'cover',
            'cta',
            'priority',
            'tags',
            'estimated_minutes',
            'status',
            'published_at',
            'updated_at',
            'canonical_id',
            'canonical_url',
        ] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $this->assertNotSame('', trim((string) $first['id']));
        $this->assertNotSame('', trim((string) $first['type']));
        $this->assertNotSame('', trim((string) $first['title']));
        $this->assertIsArray($first['tags']);
    }

    /**
     * @return list<string>
     */
    private function collectAccessLevels(mixed $node): array
    {
        $levels = [];

        $walk = function (mixed $value) use (&$walk, &$levels): void {
            if (! is_array($value)) {
                return;
            }

            if (array_key_exists('access_level', $value) && is_string($value['access_level'])) {
                $levels[] = strtolower((string) $value['access_level']);
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        $walk($node);

        return array_values(array_unique($levels));
    }
}
