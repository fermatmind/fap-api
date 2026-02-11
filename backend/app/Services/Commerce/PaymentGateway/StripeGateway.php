<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;

class StripeGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function verifySignature(Request $request): bool
    {
        $secret = trim((string) config('services.stripe.webhook_secret', ''));
        if ($secret === '') {
            return false;
        }

        $header = trim((string) $request->header('Stripe-Signature', ''));
        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $chunk, 2));
            if ($key === 't') {
                if ($value === '' || !ctype_digit($value)) {
                    return false;
                }
                $timestamp = (int) $value;
                continue;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $tolerance = (int) config(
            'services.stripe.webhook_tolerance_seconds',
            config('services.stripe.webhook_tolerance', 300)
        );
        if ($tolerance <= 0) {
            $tolerance = 300;
        }

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function normalizePayload(array $payload): array
    {
        $object = $this->resolveObject($payload);

        $providerEventId = $this->resolveString($payload, ['id']);
        if ($providerEventId === '') {
            $providerEventId = $this->resolveString($object, ['id', 'charge', 'payment_intent']);
        }

        $eventType = $this->resolveEventType($payload, $object);

        $orderNo = $this->resolveString($payload, ['order_no', 'orderNo', 'order']);
        if ($orderNo === '') {
            $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $orderNo = $this->resolveString($metadata, ['order_no', 'orderNo', 'order']);
        }

        $externalTradeNo = $this->resolveString($object, ['id', 'charge', 'payment_intent']);
        $amountCents = $this->resolveAmountCents($object, ['amount', 'amount_total', 'amount_captured']);
        if ($amountCents === 0) {
            $amountCents = $this->resolveAmountCents($payload, ['amount', 'amount_total']);
        }

        $currency = $this->resolveString($object, ['currency']);
        if ($currency === '') {
            $currency = $this->resolveString($payload, ['currency']);
        }
        if ($currency === '') {
            $currency = 'USD';
        }

        $refundAmountCents = $this->resolveRefundAmountCents($payload, $object);
        $refundReason = $this->resolveRefundReason($object);

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'paid_at' => $this->resolvePaidAt($object),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'event_type' => $eventType,
            'refund_amount_cents' => $refundAmountCents,
            'refund_reason' => $refundReason,
        ];
    }

    private function resolveObject(array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data) && is_array($data['object'] ?? null)) {
            return $data['object'];
        }

        return [];
    }

    private function resolveEventType(array $payload, array $object): string
    {
        $eventType = $this->resolveString($payload, ['type', 'event_type']);
        if ($eventType !== '') {
            return strtolower($eventType);
        }

        $refundAmount = $this->resolveRefundAmountCents($payload, $object);
        if ($refundAmount > 0 || ($object['refunded'] ?? false)) {
            return 'charge.refunded';
        }

        return 'payment_succeeded';
    }

    private function resolveRefundAmountCents(array $payload, array $object): int
    {
        $amount = $this->resolveAmountCents($object, ['amount_refunded', 'amount_refund', 'amount_refunds']);
        if ($amount > 0) {
            return $amount;
        }

        $refunds = $object['refunds']['data'] ?? null;
        if (is_array($refunds)) {
            $sum = 0;
            foreach ($refunds as $refund) {
                if (is_array($refund) && is_numeric($refund['amount'] ?? null)) {
                    $sum += (int) round((float) $refund['amount']);
                }
            }
            if ($sum > 0) {
                return $sum;
            }
        }

        return $this->resolveAmountCents($payload, ['refund_amount_cents', 'refund_amount', 'amount_refunded']);
    }

    private function resolveRefundReason(array $object): ?string
    {
        $refunds = $object['refunds']['data'] ?? null;
        if (is_array($refunds) && isset($refunds[0]) && is_array($refunds[0])) {
            $reason = trim((string) ($refunds[0]['reason'] ?? ''));
            if ($reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    private function resolvePaidAt(array $object): ?string
    {
        $created = $object['created'] ?? null;
        if (is_numeric($created)) {
            $ts = (int) $created;
            if ($ts > 0) {
                return date('c', $ts);
            }
        }

        return null;
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
