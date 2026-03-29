<?php

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $data = $this->expandPayload($payload);

        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : [];
        $outTradeNo = $this->resolveString($data, ['out_trade_no']);

        $orderNo = $this->resolveString($data, ['order_no', 'attach']);
        if ($orderNo === '') {
            $orderNo = $this->normalizeOrderNoFromOutTradeNo($outTradeNo);
        }
        if ($orderNo === '' && $outTradeNo !== '') {
            $orderNo = $this->lookupOrderNoByExternalTradeNo($outTradeNo);
        }

        $externalTradeNo = $this->resolveString($data, ['external_trade_no', 'transaction_id']);
        $tradeState = strtoupper($this->resolveString($data, ['trade_state', 'trade_status']));

        $eventType = match ($tradeState) {
            'SUCCESS' => 'payment_succeeded',
            'REFUND' => 'refund_succeeded',
            'CLOSED' => 'trade_closed',
            default => strtolower(trim($this->resolveString($data, ['event_type', 'type']))) ?: 'payment_succeeded',
        };

        $providerEventId = $this->resolveString($data, ['provider_event_id', 'id']);
        if ($providerEventId === '') {
            $eventTail = $externalTradeNo !== '' ? $externalTradeNo : $orderNo;
            $providerEventId = strtolower($eventType).':'.$eventTail;
        }

        $amountCents = $this->resolveAmountCents($amount, ['total', 'payer_total']);
        if ($amountCents <= 0) {
            $amountCents = $this->resolveAmountCents($data, ['amount_cents']);
        }

        $currency = $this->resolveString($amount, ['currency', 'payer_currency']);
        if ($currency === '') {
            $currency = $this->resolveString($data, ['currency']);
        }
        if ($currency === '') {
            $currency = 'CNY';
        }

        $refundAmountCents = $this->resolveAmountCents($data, ['refund_amount_cents']);
        $refundReason = $this->resolveString($data, ['refund_reason', 'reason']);
        if ($refundReason === '') {
            $refundReason = null;
        }

        $paidAt = $this->resolveString($data, ['success_time', 'paid_at']);

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

    /**
     * Expand: payload + resource/result + nested ciphertext/resource/result string->json recursively.
     *
     * @return array<string,mixed>
     */
    private function expandPayload(array $payload): array
    {
        $expanded = $payload;

        foreach (['resource', 'result'] as $key) {
            $node = $this->parseNode($payload[$key] ?? null);
            if ($node !== []) {
                $expanded = array_merge($expanded, $node);
            }
        }

        return $expanded;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseNode(mixed $node): array
    {
        if (is_string($node) && trim($node) !== '') {
            $decoded = json_decode($node, true);
            if (is_array($decoded)) {
                $node = $decoded;
            }
        }

        if (! is_array($node)) {
            return [];
        }

        $parsed = $node;

        foreach (['resource', 'result', 'ciphertext'] as $childKey) {
            if (! array_key_exists($childKey, $node)) {
                continue;
            }

            $child = $this->parseNode($node[$childKey]);
            if ($child !== []) {
                $parsed = array_merge($parsed, $child);
            }
        }

        return $parsed;
    }

    private function normalizeOrderNoFromOutTradeNo(string $outTradeNo): string
    {
        $outTradeNo = trim($outTradeNo);
        if ($outTradeNo === '') {
            return '';
        }

        if (str_starts_with($outTradeNo, 'ord_')) {
            return $outTradeNo;
        }

        if (preg_match('/^[0-9a-fA-F]{32}$/', $outTradeNo) !== 1) {
            return '';
        }

        $hex = strtolower($outTradeNo);

        return sprintf(
            'ord_%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function lookupOrderNoByExternalTradeNo(string $outTradeNo): string
    {
        $outTradeNo = trim($outTradeNo);
        if ($outTradeNo === '') {
            return '';
        }

        try {
            $orderNo = DB::table('orders')
                ->where('external_trade_no', $outTradeNo)
                ->orderByDesc('created_at')
                ->value('order_no');

            return trim((string) $orderNo);
        } catch (\Throwable) {
            return '';
        }
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
