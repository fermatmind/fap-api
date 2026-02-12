<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\DTO;

final class NormalizedPaymentEvent
{
    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $orderNo,
        public readonly string $eventType,
        public readonly ?string $externalTradeNo,
        public readonly ?string $paidAt,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly int $refundAmountCents,
        public readonly ?string $refundReason,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'order_no' => $this->orderNo,
            'event_type' => $this->eventType,
            'external_trade_no' => $this->externalTradeNo,
            'paid_at' => $this->paidAt,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'refund_amount_cents' => $this->refundAmountCents,
            'refund_reason' => $this->refundReason,
        ] + $this->raw;
    }
}
