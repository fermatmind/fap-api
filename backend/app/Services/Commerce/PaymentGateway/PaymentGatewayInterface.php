<?php

namespace App\Services\Commerce\PaymentGateway;

interface PaymentGatewayInterface
{
    public function provider(): string;

    /**
     * Normalize provider payload into internal shape.
     *
     * Expected keys:
     * - provider_event_id (string)
     * - order_no (string)
     * - external_trade_no (string|null)
     * - paid_at (string|null)
     * - amount_cents (int)
     * - currency (string)
     */
    public function normalizePayload(array $payload): array;
}
