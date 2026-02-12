<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Parsing;

use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\Webhook\Contracts\WebhookEventParserInterface;
use App\Services\Commerce\Webhook\DTO\NormalizedPaymentEvent;

final class BillingEventParser implements WebhookEventParserInterface
{
    public function provider(): string
    {
        return 'billing';
    }

    public function parse(array $payload): NormalizedPaymentEvent
    {
        $normalized = (new BillingGateway())->normalizePayload($payload);

        return new NormalizedPaymentEvent(
            provider: 'billing',
            providerEventId: (string) ($normalized['provider_event_id'] ?? ''),
            orderNo: (string) ($normalized['order_no'] ?? ''),
            eventType: (string) ($normalized['event_type'] ?? 'payment_succeeded'),
            externalTradeNo: isset($normalized['external_trade_no']) ? (string) $normalized['external_trade_no'] : null,
            paidAt: isset($normalized['paid_at']) ? (string) $normalized['paid_at'] : null,
            amountCents: (int) ($normalized['amount_cents'] ?? 0),
            currency: (string) ($normalized['currency'] ?? ''),
            refundAmountCents: (int) ($normalized['refund_amount_cents'] ?? 0),
            refundReason: isset($normalized['refund_reason']) ? (string) $normalized['refund_reason'] : null,
            raw: $normalized,
        );
    }
}
