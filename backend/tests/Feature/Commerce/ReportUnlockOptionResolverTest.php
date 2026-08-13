<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Services\Commerce\ReportUnlockOptionResolver;
use App\Services\Report\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportUnlockOptionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('skus')->whereIn('sku', [
            'MBTI_REPORT_FULL_199',
            'SKU_BIG5_FULL_REPORT_199',
        ])->delete();
    }

    public function test_mbti_options_fail_closed_without_exact_sku_or_provider_capability(): void
    {
        config()->set('report_unlock.providers.rewarded_ad.available', false);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', false);
        config()->set('report_unlock.providers.apple_iap.available', false);
        config()->set('report_unlock.providers.gift_purchase.available', false);

        $contract = $this->resolver()->resolve(
            'MBTI',
            'zh-CN',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [ReportAccess::MODULE_CORE_FREE],
            [ReportAccess::MODULE_CORE_FULL, ReportAccess::MODULE_CAREER]
        );

        $this->assertSame('enabled', data_get($contract, 'rollout.state'));
        $this->assertSame('attempt', $contract['scope']);
        $this->assertSame('full_report', $contract['benefit']);
        $this->assertSame('none', $contract['unlock_source']);
        $this->assertFalse((bool) data_get($contract, 'unlock_options.0.available'));
        $this->assertSame('provider_unavailable', data_get($contract, 'unlock_options.0.unavailable_reason'));
        $this->assertFalse((bool) data_get($contract, 'unlock_options.1.available'));
        $this->assertSame('sku_unavailable', data_get($contract, 'unlock_options.1.unavailable_reason'));
        $this->assertNull(data_get($contract, 'unlock_options.1.price_cents'));
        $this->assertNull(data_get($contract, 'unlock_options.1.display_price'));
        $this->assertFalse((bool) data_get($contract, 'unlock_options.2.available'));
    }

    public function test_exact_backend_sku_and_capabilities_enable_three_mbti_options(): void
    {
        $this->seedExactSku();
        config()->set('report_unlock.providers.rewarded_ad.available', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('report_unlock.providers.apple_iap.available', false);
        config()->set('report_unlock.providers.gift_purchase.available', true);

        $contract = $this->resolver()->resolve(
            'MBTI',
            'zh-CN',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_PAYMENT,
            [ReportAccess::MODULE_CORE_FREE],
            ReportAccess::allDefaultModulesOffered('MBTI')
        );

        $this->assertSame('self_purchase', $contract['unlock_source']);
        $this->assertSame('payment', $contract['legacy_unlock_source']);
        $this->assertTrue((bool) data_get($contract, 'unlock_options.0.available'));
        $this->assertTrue((bool) data_get($contract, 'unlock_options.1.available'));
        $this->assertSame('MBTI_REPORT_FULL_199', data_get($contract, 'unlock_options.1.sku'));
        $this->assertSame(199, data_get($contract, 'unlock_options.1.price_cents'));
        $this->assertSame('CNY', data_get($contract, 'unlock_options.1.currency'));
        $this->assertSame('¥1.99', data_get($contract, 'unlock_options.1.display_price'));
        $this->assertSame(['wechat_mini_virtual'], data_get($contract, 'unlock_options.1.providers'));
        $this->assertTrue((bool) data_get($contract, 'unlock_options.2.available'));
    }

    public function test_wrong_price_sku_is_not_exposed_as_an_available_product(): void
    {
        $this->seedExactSku(299);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);

        $contract = $this->resolver()->resolve(
            'MBTI',
            'zh-CN',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [ReportAccess::MODULE_CORE_FREE],
            ReportAccess::allDefaultModulesOffered('MBTI')
        );

        $this->assertFalse((bool) data_get($contract, 'unlock_options.1.available'));
        $this->assertSame('sku_unavailable', data_get($contract, 'unlock_options.1.unavailable_reason'));
        $this->assertNull(data_get($contract, 'unlock_options.1.sku'));
        $this->assertNull(data_get($contract, 'unlock_options.1.price_cents'));
        $this->assertNull(data_get($contract, 'unlock_options.1.display_price'));
    }

    public function test_non_mbti_and_iq_rollouts_stay_disabled(): void
    {
        config()->set('report_unlock.rollout_scales', ['MBTI', 'BIG5_OCEAN', 'IQ_RAVEN']);
        config()->set('report_unlock.providers.rewarded_ad.available', true);

        $bigFive = $this->resolver()->resolve(
            'BIG5_OCEAN',
            'en',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [ReportAccess::MODULE_BIG5_CORE],
            [ReportAccess::MODULE_BIG5_FULL]
        );
        $iq = $this->resolver()->resolve(
            'IQ_RAVEN',
            'zh-CN',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [ReportAccess::MODULE_IQ_CORE],
            [ReportAccess::MODULE_IQ_FULL]
        );

        $this->assertSame('disabled', data_get($bigFive, 'rollout.state'));
        $this->assertFalse((bool) data_get($bigFive, 'unlock_options.0.available'));
        $this->assertSame('disabled', data_get($iq, 'rollout.state'));
        $this->assertFalse((bool) data_get($iq, 'rollout.iq_readiness'));
        $this->assertFalse((bool) data_get($iq, 'unlock_options.0.available'));
    }

    public function test_allowlisted_big_five_attempt_exposes_the_199_three_channel_contract(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_big5_contract',
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_version' => 'v1',
            'question_count' => 0,
            'answers_summary_json' => [],
            'client_platform' => 'test',
            'started_at' => now(),
        ]);
        $this->seedBigFiveSku();
        config()->set('report_unlock.big5_rollout.mode', 'allowlist_only');
        config()->set('report_unlock.big5_rollout.allowed_attempt_ids', [$attempt->id]);
        config()->set('report_unlock.providers.rewarded_ad.available', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('report_unlock.providers.gift_purchase.available', true);

        $contract = $this->resolver()->resolve(
            'BIG5_OCEAN',
            'zh-CN',
            0,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [ReportAccess::MODULE_BIG5_CORE],
            [ReportAccess::MODULE_BIG5_FULL, ReportAccess::MODULE_BIG5_ACTION_PLAN],
            null,
            $attempt,
        );

        $this->assertSame('enabled', data_get($contract, 'rollout.state'));
        $this->assertTrue((bool) data_get($contract, 'unlock_options.0.available'));
        $this->assertTrue((bool) data_get($contract, 'unlock_options.1.available'));
        $this->assertSame('SKU_BIG5_FULL_REPORT_199', data_get($contract, 'unlock_options.1.sku'));
        $this->assertSame(199, data_get($contract, 'unlock_options.1.price_cents'));
        $this->assertSame('¥1.99', data_get($contract, 'unlock_options.1.display_price'));
        $this->assertTrue((bool) data_get($contract, 'unlock_options.2.available'));
    }

    public function test_three_channel_source_normalization_excludes_legacy_invite_and_mixed(): void
    {
        $this->assertSame('rewarded_ad', ReportAccess::normalizeThreeChannelUnlockSource('rewarded_ad'));
        $this->assertSame('self_purchase', ReportAccess::normalizeThreeChannelUnlockSource('payment'));
        $this->assertSame('gift_purchase', ReportAccess::normalizeThreeChannelUnlockSource('gift_purchase'));
        $this->assertSame('none', ReportAccess::normalizeThreeChannelUnlockSource('invite'));
        $this->assertSame('none', ReportAccess::normalizeThreeChannelUnlockSource('mixed'));
    }

    private function resolver(): ReportUnlockOptionResolver
    {
        return $this->app->make(ReportUnlockOptionResolver::class);
    }

    private function seedExactSku(int $priceCents = 199): void
    {
        DB::table('skus')->insert([
            'sku' => 'MBTI_REPORT_FULL_199',
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'price_cents' => $priceCents,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => json_encode([
                'modules_included' => [
                    ReportAccess::MODULE_CORE_FULL,
                    ReportAccess::MODULE_CAREER,
                    ReportAccess::MODULE_RELATIONSHIPS,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBigFiveSku(): void
    {
        DB::table('skus')->insert([
            'sku' => 'SKU_BIG5_FULL_REPORT_199',
            'scale_code' => 'BIG5_OCEAN',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'BIG5_FULL_REPORT',
            'scope' => 'attempt',
            'price_cents' => 199,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => json_encode([
                'effective_default' => true,
                'modules_included' => [
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
