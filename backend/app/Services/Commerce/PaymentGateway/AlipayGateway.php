<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;

class AlipayGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'alipay';
    }

    public function verifySignature(Request $request): bool
    {
        // Alipay callback verification is handled by yansongda/pay callback().
        return true;
    }

    public function normalizePayload(array $payload): array
    {
        $orderNo = $this->resolveString($payload, ['order_no', 'out_trade_no']);
        $externalTradeNo = $this->resolveString($payload, ['external_trade_no', 'trade_no']);
        $tradeStatus = strtoupper($this->resolveString($payload, ['trade_status']));

        $eventType = match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => 'payment_succeeded',
            'TRADE_CLOSED' => 'trade_closed',
            default => strtolower(trim($this->resolveString($payload, ['event_type', 'type']))) ?: 'payment_succeeded',
        };

        $providerEventId = $this->resolveString($payload, ['provider_event_id', 'notify_id']);
        if ($providerEventId === '') {
            $eventTail = $externalTradeNo !== '' ? $externalTradeNo : $orderNo;
            $providerEventId = strtolower($eventType).':'.$eventTail;
        }

        $amountCents = $this->resolveYuanAmountToCents($payload, ['total_amount', 'receipt_amount', 'buyer_pay_amount']);
        if ($amountCents <= 0) {
            $amountCents = $this->resolveAmountCents($payload, ['amount_cents', 'total_fee']);
        }

        $refundAmountCents = $this->resolveYuanAmountToCents($payload, ['refund_fee', 'refund_amount']);
        if ($refundAmountCents <= 0) {
            $refundAmountCents = $this->resolveAmountCents($payload, ['refund_amount_cents']);
        }

        $refundReason = $this->resolveString($payload, ['refund_reason', 'reason']);
        if ($refundReason === '') {
            $refundReason = null;
        }

        $currency = $this->resolveString($payload, ['currency']);
        if ($currency === '') {
            $currency = 'CNY';
        }

        $paidAt = $this->resolveString($payload, ['gmt_payment', 'paid_at']);

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'paid_at' => $paidAt !== '' ? $paidAt : null,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'event_type' => strtolower($eventType),
            'refund_amount_cents' => $refundAmountCents,
            'refund_reason' => $refundReason,
        ];
    }

    private function resolveString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveAmountCents(array $payload, array $keys): int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $raw = $payload[$key];
            if (is_numeric($raw)) {
                return max(0, (int) round((float) $raw));
            }
        }

        return 0;
    }

    private function resolveYuanAmountToCents(array $payload, array $keys): int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $raw = trim((string) ($payload[$key] ?? ''));
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^-?\d+(\.\d+)?$/', $raw) !== 1) {
                continue;
            }

            return max(0, (int) round(((float) $raw) * 100));
        }

        return 0;
    }
}
