<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\ReportUnlockProductCatalog;
use Tests\TestCase;

final class ReportUnlockProductCatalogTest extends TestCase
{
    public function test_big_five_contract_uses_published_product_and_499_sku(): void
    {
        $catalog = app(ReportUnlockProductCatalog::class);
        $contract = $catalog->forScale('BIG5_OCEAN');

        self::assertSame('SKU_BIG5_FULL_REPORT_499', $contract['sku']);
        self::assertSame('BIG5_FULL_REPORT', $contract['benefit_code']);
        self::assertSame(499, $contract['price_cents']);
        self::assertSame('BigFive', $catalog->provider('wechat_mini_virtual', $contract)['product_id']);
        self::assertSame('BigFive', $catalog->provider('apple_iap', $contract)['product_id']);
    }

    public function test_mbti_legacy_scalar_provider_contract_is_unchanged(): void
    {
        config()->set('payments.wechat_mini_virtual.product_id', 'MBTI');
        $catalog = app(ReportUnlockProductCatalog::class);
        $contract = $catalog->forScale('MBTI');

        self::assertSame('MBTI_REPORT_FULL', $contract['benefit_code']);
        self::assertSame('MBTI', $catalog->provider('wechat_mini_virtual', $contract)['product_id']);
    }
}
