<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\SkuCatalog;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiCommerceConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_catalog_keeps_org_skus_but_public_endpoint_exposes_no_assessment_offer(): void
    {
        $this->seedScales();

        /** @var SkuCatalog $catalog */
        $catalog = app(SkuCatalog::class);
        $catalogSkus = collect($catalog->listActiveSkus('MBTI'))
            ->pluck('sku')
            ->filter(fn ($sku): bool => is_string($sku) && $sku !== '')
            ->values()
            ->all();

        $this->assertNotContains('MBTI_REPORT_FULL', $catalogSkus);
        $this->assertNotContains('MBTI_REPORT_FULL_199', $catalogSkus);
        $this->assertNotContains('MBTI_CAREER_99', $catalogSkus);
        $this->assertNotContains('MBTI_RELATIONSHIP_99', $catalogSkus);
        $this->assertContains('MBTI_PRO_MONTH_599', $catalogSkus);
        $this->assertContains('MBTI_PRO_YEAR_1999', $catalogSkus);
        $this->assertContains('MBTI_GIFT_PACK_2990', $catalogSkus);
        $this->assertContains('MBTI_CREDIT', $catalogSkus);

        $response = $this->getJson('/api/v0.3/skus?scale=MBTI');
        $response->assertOk()->assertJsonPath('ok', true);

        $apiSkus = collect((array) $response->json('items'))
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['sku'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        $this->assertSame([], $apiSkus);
    }

    public function test_mbti_report_is_full_free_without_offer(): void
    {
        $this->seedScales();

        $anonId = 'anon_mbti_consolidated_offers';
        $attemptId = $this->createAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('variant', 'full')
            ->assertJsonPath('access_source', 'scale_free_only')
            ->assertJsonPath('paywall_suppressed', true)
            ->assertJsonPath('upgrade_sku', null)
            ->assertJsonPath('upgrade_sku_effective', null)
            ->assertJsonPath('cta.visible', false)
            ->assertJsonCount(0, 'offers');

        $offerSkus = collect((array) $response->json('offers'))
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['sku'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        $this->assertSame([], $offerSkus);
    }

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

    private function createAttemptWithResult(string $anonId): string
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
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
