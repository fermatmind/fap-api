<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation;

use App\Services\Commerce\Compensation\Contracts\PaymentLifecycleGatewayInterface;
use App\Services\Commerce\Compensation\Gateways\AlipayLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\BillingLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\LemonSqueezyLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\StripeLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\WechatPayLifecycleGateway;

final class PaymentLifecycleGatewayRegistry
{
    public function for(string $provider): ?PaymentLifecycleGatewayInterface
    {
        return match (strtolower(trim($provider))) {
            'wechatpay' => app(WechatPayLifecycleGateway::class),
            'alipay' => app(AlipayLifecycleGateway::class),
            'billing' => app(BillingLifecycleGateway::class),
            'stripe' => app(StripeLifecycleGateway::class),
            'lemonsqueezy' => app(LemonSqueezyLifecycleGateway::class),
            default => null,
        };
    }
}
