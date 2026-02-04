<?php

namespace App\Services\Commerce\PaymentGateway;

class BillingGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'billing';
    }

    public function normalizePayload(array $payload): array
    {
        $providerEventId = $this->resolveString($payload, ['provider_event_id', 'event_id', 'id']);
        $orderNo = $this->resolveString($payload, ['order_no', 'orderNo', 'order']);
        $externalTradeNo = $this->resolveString($payload, ['external_trade_no', 'trade_no', 'transaction_id']);
        $amountCents = $this->resolveAmountCents($payload, ['amount_cents', 'amount', 'amount_total']);
        $currency = $this->resolveString($payload, ['currency']);
        if ($currency === '') {
            $currency = 'USD';
        }

        $eventType = $this->resolveEventType($payload);
        $refundAmountCents = $this->resolveRefundAmountCents($payload);
        $refundReason = $this->resolveString($payload, ['refund_reason', 'reason']);
        if ($refundReason === '') {
            $refundReason = null;
        }

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'paid_at' => $this->resolvePaidAt($payload),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'event_type' => $eventType,
            'refund_amount_cents' => $refundAmountCents,
            'refund_reason' => $refundReason,
        ];
    }

    private function resolveEventType(array $payload): string
    {
        $eventType = $this->resolveString($payload, ['event_type', 'eventType', 'type']);
        if ($eventType !== '') {
            return strtolower($eventType);
        }

        if ($this->resolveRefundAmountCents($payload) > 0) {
            return 'refund_succeeded';
        }

        return 'payment_succeeded';
    }

    private function resolvePaidAt(array $payload): ?string
    {
        foreach (['paid_at', 'paidAt', 'paid_time', 'paidTime'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $raw = $payload[$key];
            if (is_numeric($raw)) {
                $ts = (int) $raw;
                if ($ts > 0) {
                    return date('c', $ts);
                }
            }

            $value = trim((string) $raw);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveRefundAmountCents(array $payload): int
    {
        foreach (['refund_amount_cents', 'refund_amount', 'amount_refunded', 'refund_amount_total'] as $key) {
            if (array_key_exists($key, $payload)) {
                $raw = $payload[$key];
                if (is_numeric($raw)) {
                    return (int) round((float) $raw);
                }
            }
        }

        return 0;
    }

    private function resolveString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolveAmountCents(array $payload, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $raw = $payload[$key];
                if (is_numeric($raw)) {
                    return (int) round((float) $raw);
                }
            }
        }

        return 0;
    }
}
