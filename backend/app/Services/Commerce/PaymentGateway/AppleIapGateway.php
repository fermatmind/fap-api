<?php

declare(strict_types=1);

namespace App\Services\Commerce\PaymentGateway;

final class AppleIapGateway extends WechatMiniVirtualGateway
{
    public const PROVIDER = 'apple_iap';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    protected function callbackToken(): string
    {
        return trim((string) config('payments.apple_iap.callback_token', ''));
    }
}
