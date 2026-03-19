<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Services\Payments\PaymentRouter;
use Tests\TestCase;

final class PaymentRouterTest extends TestCase
{
    public function test_primary_provider_override_can_switch_cn_mainland_to_alipay(): void
    {
        config([
            'payments.providers.wechatpay.enabled' => true,
            'payments.providers.alipay.enabled' => true,
            'payments.primary_provider_overrides.CN_MAINLAND' => 'alipay',
        ]);

        $router = app(PaymentRouter::class);

        $this->assertSame('alipay', $router->primaryProviderForRegion('CN_MAINLAND'));
        $this->assertSame(['alipay', 'wechatpay', 'billing', 'stripe'], $router->methodsForRegion('CN_MAINLAND'));
    }
}
