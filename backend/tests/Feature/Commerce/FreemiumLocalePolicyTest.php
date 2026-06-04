<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use Carbon\Carbon;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FreemiumLocalePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_english_sku_policy_returns_no_cny_paywall_offer(): void
    {
        Carbon::setTestNow('2026-06-04 12:00:00');
        $this->seedScales();

        $response = $this->getJson('/api/v0.3/skus?scale=MBTI&locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('locale_freemium_policy.authority', 'backend')
            ->assertJsonPath('locale_freemium_policy.locale_family', 'en')
            ->assertJsonPath('locale_freemium_policy.policy', 'free_until')
            ->assertJsonPath('locale_freemium_policy.free_until', '2026-12-31')
            ->assertJsonPath('locale_freemium_policy.english_free_active', true)
            ->assertJsonPath('locale_freemium_policy.paywall_allowed', false)
            ->assertJsonPath('locale_freemium_policy.order_creation_allowed', false)
            ->assertJsonPath('locale_freemium_policy.currency', null)
            ->assertJsonPath('locale_freemium_policy.sku', null);

        $skus = collect((array) $response->json('items'))
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['sku'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        $this->assertNotContains('MBTI_REPORT_FULL_199', $skus);
    }

    public function test_chinese_sku_policy_returns_cny_199_unlock_when_enabled(): void
    {
        $this->seedScales();

        $response = $this->getJson('/api/v0.3/skus?scale=MBTI&locale=zh-CN');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('locale_freemium_policy.authority', 'backend')
            ->assertJsonPath('locale_freemium_policy.locale_family', 'zh')
            ->assertJsonPath('locale_freemium_policy.policy', 'cny_199_unlock')
            ->assertJsonPath('locale_freemium_policy.paywall_allowed', true)
            ->assertJsonPath('locale_freemium_policy.order_creation_allowed', true)
            ->assertJsonPath('locale_freemium_policy.currency', 'CNY')
            ->assertJsonPath('locale_freemium_policy.price_cents', 199)
            ->assertJsonPath('locale_freemium_policy.sku', 'MBTI_REPORT_FULL_199')
            ->assertJsonPath('locale_freemium_policy.upgrade_sku', 'MBTI_REPORT_FULL');

        $items = (array) $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('MBTI_REPORT_FULL_199', (string) data_get($items, '0.sku'));
        $this->assertSame('CNY', (string) data_get($items, '0.currency'));
        $this->assertSame(199, (int) data_get($items, '0.price_cents'));
    }

    public function test_english_mbti_report_is_full_free_with_no_cny_offer(): void
    {
        Carbon::setTestNow('2026-06-04 12:00:00');
        $this->seedScales();

        $anonId = 'anon_freemium_en_report';
        $attemptId = $this->createMbtiAttemptWithResult($anonId, 'en');
        $token = $this->issueAnonToken($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'X-Fap-Locale' => 'en',
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('variant', 'full')
            ->assertJsonPath('upgrade_sku', null)
            ->assertJsonPath('upgrade_sku_effective', null)
            ->assertJsonPath('cta.visible', false)
            ->assertJsonPath('locale_freemium_policy.locale_family', 'en')
            ->assertJsonPath('locale_freemium_policy.paywall_allowed', false);

        $this->assertSame([], (array) $response->json('offers'));
    }

    public function test_english_cny_order_attempt_is_rejected_before_order_creation(): void
    {
        Carbon::setTestNow('2026-06-04 12:00:00');
        $this->seedScales();

        $anonId = 'anon_freemium_en_order';
        $attemptId = $this->createMbtiAttemptWithResult($anonId, 'en');
        $token = $this->issueAnonToken($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'X-Fap-Locale' => 'en',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders', [
            'sku' => 'MBTI_REPORT_FULL_199',
            'provider' => 'billing',
            'target_attempt_id' => $attemptId,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'LOCALE_POLICY_ORDER_NOT_ALLOWED')
            ->assertJsonPath('details.locale_freemium_policy.locale_family', 'en')
            ->assertJsonPath('details.locale_freemium_policy.paywall_allowed', false);

        $this->assertSame(0, DB::table('orders')->count());
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
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createMbtiAttemptWithResult(string $anonId, string $locale): string
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
            'locale' => $locale,
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
            'result_json' => [
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
                    'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
                ],
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
}
