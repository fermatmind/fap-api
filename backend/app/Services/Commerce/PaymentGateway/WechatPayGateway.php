<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;

class WechatPayGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'wechatpay';
    }

    public function verifySignature(Request $request): bool
    {
        // WeChat callback verification/decryption is handled by yansongda/pay callback().
        return true;
    }

    public function normalizePayload(array $payload): array
    {
        $amount = is_array($payload['amount'] ?? null) ? $payload['amount'] : [];
        $orderNo = $this->resolveString($payload, ['order_no', 'out_trade_no']);
        $externalTradeNo = $this->resolveString($payload, ['external_trade_no', 'transaction_id']);
        $tradeState = strtoupper($this->resolveString($payload, ['trade_state', 'trade_status']));

        $eventType = match ($tradeState) {
            'SUCCESS' => 'payment_succeeded',
            'REFUND' => 'refund_succeeded',
            'CLOSED' => 'trade_closed',
            default => strtolower(trim($this->resolveString($payload, ['event_type', 'type']))) ?: 'payment_succeeded',
        };

        $providerEventId = $this->resolveString($payload, ['provider_event_id', 'id']);
        if ($providerEventId === '') {
            $eventTail = $externalTradeNo !== '' ? $externalTradeNo : $orderNo;
            $providerEventId = strtolower($eventType).':'.$eventTail;
        }

        $amountCents = $this->resolveAmountCents($amount, ['total', 'payer_total']);
        if ($amountCents <= 0) {
            $amountCents = $this->resolveAmountCents($payload, ['amount_cents', 'total']);
        }

        $currency = $this->resolveString($amount, ['currency', 'payer_currency']);
        if ($currency === '') {
            $currency = $this->resolveString($payload, ['currency']);
        }
        if ($currency === '') {
            $currency = 'CNY';
        }

        $refundAmountCents = $this->resolveAmountCents($payload, ['refund_amount_cents']);
        $refundReason = $this->resolveString($payload, ['refund_reason', 'reason']);
        if ($refundReason === '') {
            $refundReason = null;
        }

        $paidAt = $this->resolveString($payload, ['success_time', 'paid_at']);

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
}
