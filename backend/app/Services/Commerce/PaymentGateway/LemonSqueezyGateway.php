<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;

class LemonSqueezyGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'lemonsqueezy';
    }

    public function verifySignature(Request $request): bool
    {
        $secret = trim((string) config('services.lemonsqueezy.webhook_secret', ''));
        if ($secret === '') {
            $secret = trim((string) config('payments.lemonsqueezy.webhook_secret', ''));
        }
        if ($secret === '') {
            return false;
        }

        $provided = trim((string) $request->header('X-Signature', ''));
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals(strtolower($expected), strtolower($provided));
    }

    public function normalizePayload(array $payload): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $customData = is_array($meta['custom_data'] ?? null)
            ? $meta['custom_data']
            : (is_array($attributes['custom_data'] ?? null) ? $attributes['custom_data'] : []);

        $eventType = strtolower(trim((string) (
            $meta['event_name']
            ?? $payload['event_name']
            ?? $payload['type']
            ?? 'payment_succeeded'
        )));
        if ($eventType === '') {
            $eventType = 'payment_succeeded';
        }

        $orderNo = $this->resolveString($customData, ['order_no', 'orderNo', 'order']);
        if ($orderNo === '') {
            $orderNo = $this->resolveString($attributes, ['order_no', 'identifier']);
        }

        $dataId = $this->resolveString($data, ['id']);
        $providerEventId = $this->resolveString($payload, ['provider_event_id', 'event_id', 'id']);
        if ($providerEventId === '') {
            $providerEventId = $eventType.':'.($dataId !== '' ? $dataId : $orderNo);
        }

        $amountCents = $this->resolveAmountCents($customData, ['amount_cents']);
        if ($amountCents <= 0) {
            $amountCents = $this->resolveAmountCents($attributes, [
                'subtotal',
                'total',
                'grand_total',
                'subtotal_usd',
                'total_usd',
            ]);
        }
        if ($amountCents <= 0) {
            $amountCents = $this->resolveAmountCents($payload, ['amount_cents', 'amount']);
        }

        $currency = $this->resolveString($customData, ['currency']);
        if ($currency === '') {
            $currency = $this->resolveString($attributes, ['currency', 'currency_code']);
        }
        if ($currency === '') {
            $currency = $this->resolveString($payload, ['currency']);
        }
        if ($currency === '') {
            $currency = 'USD';
        }

        $refundAmountCents = $this->resolveAmountCents($customData, ['refund_amount_cents']);
        if ($refundAmountCents <= 0) {
            $refundAmountCents = $this->resolveAmountCents($attributes, [
                'refund_amount',
                'refund_amount_cents',
            ]);
        }

        $refundReason = $this->resolveString($attributes, ['refund_reason', 'reason']);
        if ($refundReason === '') {
            $refundReason = null;
        }

        $paidAt = $this->resolveString($attributes, ['created_at', 'updated_at', 'paid_at']);
        if ($paidAt === '') {
            $paidAt = $this->resolveString($payload, ['created_at', 'updated_at']);
        }

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $dataId !== '' ? $dataId : null,
            'paid_at' => $paidAt !== '' ? $paidAt : null,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'event_type' => $eventType,
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
            if (is_int($raw)) {
                return max(0, $raw);
            }

            if (is_float($raw)) {
                if (fmod($raw, 1.0) !== 0.0) {
                    return max(0, (int) round($raw * 100));
                }

                return max(0, (int) round($raw));
            }

            if (is_string($raw)) {
                $value = trim($raw);
                if ($value === '') {
                    continue;
                }

                if (preg_match('/^-?\d+$/', $value) === 1) {
                    return max(0, (int) $value);
                }

                if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
                    return max(0, (int) round(((float) $value) * 100));
                }
            }
        }

        return 0;
    }
}
