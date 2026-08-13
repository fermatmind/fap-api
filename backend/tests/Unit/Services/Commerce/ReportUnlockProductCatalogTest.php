<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\ReportUnlockProductCatalog;
use Tests\TestCase;

final class ReportUnlockProductCatalogTest extends TestCase
{
    public function test_all_wechat_report_contracts_share_the_199_product(): void
    {
        $catalog = app(ReportUnlockProductCatalog::class);
        $sharedProductId = (string) config('payments.wechat_mini_virtual.product_id');
        self::assertSame('FullReport199', $sharedProductId);
        foreach (['MBTI', 'BIG5_OCEAN', 'LOCAL_REPORT'] as $scale) {
            $contract = $catalog->forScale($scale);
            self::assertSame(199, $contract['price_cents']);
            self::assertSame($sharedProductId, $catalog->provider('wechat_mini_virtual', $contract)['product_id']);
            self::assertSame($sharedProductId, $catalog->provider('apple_iap', $contract)['product_id']);
        }

        self::assertSame('SKU_BIG5_FULL_REPORT_199', $catalog->forScale('BIG5_OCEAN')['sku']);
        self::assertSame('WEAPP_LOCAL_REPORT_FULL_199', $catalog->forScale('LOCAL_REPORT')['sku']);
    }

    public function test_legacy_environment_values_cannot_override_the_shared_contract(): void
    {
        $legacy = [
            'WECHAT_MINI_VIRTUAL_PRODUCT_ID' => 'MBTI',
            'WECHAT_MINI_VIRTUAL_SKU' => 'MBTI_REPORT_FULL',
            'WECHAT_MINI_VIRTUAL_PRICE_CENTS' => '499',
            'APPLE_IAP_WECHAT_PRODUCT_ID' => 'MBTI',
            'APPLE_IAP_WECHAT_SKU' => 'MBTI_REPORT_FULL',
            'APPLE_IAP_WECHAT_PRICE_CENTS' => '499',
            'REPORT_UNLOCK_MBTI_SKU' => 'MBTI_REPORT_FULL',
            'REPORT_UNLOCK_BIG5_SKU' => 'SKU_BIG5_FULL_REPORT_499',
        ];
        $previous = [];
        foreach ($legacy as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }

        try {
            $payments = require config_path('payments.php');
            $reportUnlock = require config_path('report_unlock.php');

            self::assertSame('FullReport199', $payments['wechat_mini_virtual']['product_id']);
            self::assertSame('MBTI_REPORT_FULL_199', $payments['wechat_mini_virtual']['sku']);
            self::assertSame(199, $payments['wechat_mini_virtual']['price_cents']);
            self::assertSame('FullReport199', $payments['apple_iap']['product_id']);
            self::assertSame('MBTI_REPORT_FULL_199', $payments['apple_iap']['sku']);
            self::assertSame(199, $payments['apple_iap']['price_cents']);
            self::assertSame('MBTI_REPORT_FULL_199', $reportUnlock['products']['MBTI']['sku']);
            self::assertSame('SKU_BIG5_FULL_REPORT_199', $reportUnlock['products']['BIG5_OCEAN']['sku']);
            self::assertSame('WEAPP_LOCAL_REPORT_FULL_199', $reportUnlock['products']['LOCAL_REPORT']['sku']);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
    }
}
