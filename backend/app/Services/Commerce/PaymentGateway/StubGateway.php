<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Support\Str;

class StubGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'stub';
    }

    public function normalizePayload(array $payload): array
    {
        $providerEventId = $this->resolveString($payload, ['provider_event_id', 'event_id', 'id']);
        if ($providerEventId === '') {
            $providerEventId = (string) Str::uuid();
        }

        $orderNo = $this->resolveString($payload, ['order_no', 'orderNo', 'order']);
        $externalTradeNo = $this->resolveString($payload, ['external_trade_no', 'trade_no', 'tradeNo']);
        $paidAt = $this->resolvePaidAt($payload);
        $amountCents = $this->resolveAmountCents($payload);
        $currency = $this->resolveString($payload, ['currency']);
        if ($currency === '') {
            $currency = 'USD';
        }

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'paid_at' => $paidAt,
            'amount_cents' => $amountCents,
            'currency' => $currency,
        ];
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

    private function resolveAmountCents(array $payload): int
    {
        foreach (['amount_cents', 'amount', 'amount_total'] as $key) {
            if (array_key_exists($key, $payload)) {
                $raw = $payload[$key];
                if (is_numeric($raw)) {
                    return (int) round((float) $raw);
                }
            }
        }

        return 0;
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
}
