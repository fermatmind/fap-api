<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiReportContentEnhancementContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const MBTI_TYPES = [
        'ENFJ-A', 'ENFJ-T', 'ENFP-A', 'ENFP-T',
        'ENTJ-A', 'ENTJ-T', 'ENTP-A', 'ENTP-T',
        'ESFJ-A', 'ESFJ-T', 'ESFP-A', 'ESFP-T',
        'ESTJ-A', 'ESTJ-T', 'ESTP-A', 'ESTP-T',
        'INFJ-A', 'INFJ-T', 'INFP-A', 'INFP-T',
        'INTJ-A', 'INTJ-T', 'INTP-A', 'INTP-T',
        'ISFJ-A', 'ISFJ-T', 'ISFP-A', 'ISFP-T',
        'ISTJ-A', 'ISTJ-T', 'ISTP-A', 'ISTP-T',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fap.runtime.CONTENT_GRAPH_ENABLED', true);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();
    }

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

    private function createAttemptWithResult(string $anonId, string $typeCode): string
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

        $scoresPct = $this->scoresPctForType($typeCode);
        $scoresJson = [];
        foreach ($scoresPct as $dim => $pct) {
            if ($pct >= 50) {
                $scoresJson[$dim] = ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => 4, 'total' => 20];
            } else {
                $scoresJson[$dim] = ['a' => 8, 'b' => 12, 'neutral' => 0, 'sum' => -4, 'total' => 20];
            }
        }

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => $scoresJson,
            'scores_pct' => $scoresPct,
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
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

    public function test_identity_layers_are_authored_for_all_32_types_and_report_uses_authored_payload(): void
    {
        $doc = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/identity_layers.json')), true);
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];

        $this->assertCount(32, $items);

        foreach (self::MBTI_TYPES as $typeCode) {
            $layer = $items[$typeCode] ?? null;
            $this->assertIsArray($layer, "identity_layers item missing for {$typeCode}");
            $this->assertNotSame('', trim((string) ($layer['title'] ?? '')));
            $this->assertNotSame('', trim((string) ($layer['subtitle'] ?? '')));
            $this->assertNotSame('', trim((string) ($layer['one_liner'] ?? '')));
            $this->assertNotEmpty((array) ($layer['bullets'] ?? []));
            $this->assertNotEmpty((array) ($layer['tags'] ?? []));
        }

        $this->seedScales();
        $anonId = 'anon_contract_identity';
        $token = $this->issueAnonToken($anonId);

        foreach (self::MBTI_TYPES as $typeCode) {
            $attemptId = $this->createAttemptWithResult($anonId, $typeCode);

            $resp = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer ' . $token,
            ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

            $resp->assertStatus(200);
            $layer = (array) $resp->json('report.layers.identity');
            $this->assertNotEmpty($layer, "report.layers.identity missing for {$typeCode}");
            $this->assertNotSame('', trim((string) ($layer['title'] ?? '')));
            $this->assertNotSame('', trim((string) ($layer['subtitle'] ?? '')));
            $this->assertNotSame('', trim((string) ($layer['one_liner'] ?? '')));
            $this->assertNotEmpty((array) ($layer['bullets'] ?? []));
            $this->assertNotContains('fallback:true', (array) ($layer['tags'] ?? []), "identity layer should not fallback for {$typeCode}");
        }
    }

    public function test_report_contract_exposes_stable_recommended_reads_and_cta_shapes(): void
    {
        $this->seedScales();

        $anonId = 'anon_contract_shapes';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId, 'INTJ-A');

        $locked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $locked->assertStatus(200);
        $locked->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
            'variant' => 'free',
        ]);
        $this->assertStableCtaShape((array) $locked->json('cta'));
        $this->assertTrue((bool) $locked->json('cta.visible'));
        $this->assertIsArray($locked->json('report.recommended_reads'));
        $this->assertStableRecommendedReadShape((array) ($locked->json('report.recommended_reads.0') ?? []));
        $this->assertSame(['traits', 'career', 'growth', 'relationships'], array_keys((array) $locked->json('report.sections')));

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

        $unlocked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $unlocked->assertStatus(200);
        $unlocked->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);
        $this->assertStableCtaShape((array) $unlocked->json('cta'));
        $this->assertFalse((bool) $unlocked->json('cta.visible'));
        $this->assertSame('none', $unlocked->json('cta.kind'));
        $this->assertIsArray($unlocked->json('report.recommended_reads'));
        $this->assertSame(['traits', 'career', 'growth', 'relationships'], array_keys((array) $unlocked->json('report.sections')));
    }

    public function test_free_and_full_gate_semantics_remain_unchanged_with_new_fields(): void
    {
        $this->seedScales();

        $anonId = 'anon_contract_gate';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId, 'ENFP-T');

        $locked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $locked->assertStatus(200);
        $this->assertNotContains('paid', $this->collectAccessLevels($locked->json('report')));

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

        $unlocked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $unlocked->assertStatus(200);
        $this->assertContains('paid', $this->collectAccessLevels($unlocked->json('report')));
    }

    /**
     * @return array<string,int>
     */
    private function scoresPctForType(string $typeCode): array
    {
        [$mbti4, $suffix] = explode('-', strtoupper($typeCode), 2);

        return [
            'EI' => $mbti4[0] === 'E' ? 64 : 36,
            'SN' => $mbti4[1] === 'S' ? 64 : 36,
            'TF' => $mbti4[2] === 'T' ? 64 : 36,
            'JP' => $mbti4[3] === 'J' ? 64 : 36,
            'AT' => $suffix === 'A' ? 64 : 36,
        ];
    }

    /**
     * @param array<string,mixed> $cta
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
        $this->assertTrue(is_array($cta['benefit_bullets']));
    }

    /**
     * @param array<string,mixed> $item
     */
    private function assertStableRecommendedReadShape(array $item): void
    {
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
            $this->assertArrayHasKey($key, $item);
        }

        $this->assertIsString($item['id']);
        $this->assertIsString($item['type']);
        $this->assertIsString($item['title']);
        $this->assertIsInt($item['priority']);
        $this->assertIsArray($item['tags']);
    }

    /**
     * @return list<string>
     */
    private function collectAccessLevels(mixed $node): array
    {
        $levels = [];

        $walk = function (mixed $value) use (&$walk, &$levels): void {
            if (is_array($value)) {
                if (array_key_exists('access_level', $value) && is_string($value['access_level'])) {
                    $levels[] = strtolower((string) $value['access_level']);
                }
                foreach ($value as $child) {
                    $walk($child);
                }
            }
        };

        $walk($node);

        return array_values(array_unique($levels));
    }
}
