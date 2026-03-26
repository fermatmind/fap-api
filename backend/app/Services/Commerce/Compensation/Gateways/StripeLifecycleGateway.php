<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation\Gateways;

use App\Services\Commerce\Compensation\Contracts\PaymentLifecycleGatewayInterface;

final class StripeLifecycleGateway implements PaymentLifecycleGatewayInterface
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function queryPaymentStatus(array $context): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'status' => 'unsupported',
            'provider_trade_no' => null,
            'paid_at' => null,
            'queried_at' => now()->toIso8601String(),
            'raw_state' => null,
            'is_terminal' => false,
            'supports_close' => false,
            'reason' => 'stripe provider query is not implemented in this project.',
        ];
    }

    public function closePayment(array $context): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'status' => 'unsupported',
            'provider_trade_no' => null,
            'closed_at' => null,
            'raw_state' => null,
            'is_terminal' => false,
            'supports_close' => false,
            'reason' => 'stripe provider close is not implemented in this project.',
        ];
    }
}
