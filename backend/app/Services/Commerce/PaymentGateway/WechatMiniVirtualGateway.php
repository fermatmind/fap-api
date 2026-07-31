<?php

declare(strict_types=1);

namespace App\Services\Commerce\PaymentGateway;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WechatMiniVirtualGateway implements PaymentGatewayInterface
{
    public const PROVIDER = 'wechat_mini_virtual';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function verifySignature(Request $request): bool
    {
        $token = trim((string) config('payments.wechat_mini_virtual.callback_token', ''));
        $timestamp = trim((string) $request->query('timestamp', ''));
        $nonce = trim((string) $request->query('nonce', ''));
        $signature = strtolower(trim((string) $request->query('signature', '')));
        if ($token === '' || $timestamp === '' || $nonce === '' || preg_match('/^[a-f0-9]{40}$/', $signature) !== 1) {
            return false;
        }

        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);

        return hash_equals(sha1(implode('', $parts)), $signature);
    }

    public function normalizePayload(array $payload): array
    {
        $orderInfo = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $goodsInfo = is_array($payload['GoodsInfo'] ?? null)
            ? $payload['GoodsInfo']
            : (is_array($payload['goods_info'] ?? null) ? $payload['goods_info'] : []);
        $wechatPayInfo = is_array($payload['WeChatPayInfo'] ?? null)
            ? $payload['WeChatPayInfo']
            : (is_array($payload['wechat_pay_info'] ?? null) ? $payload['wechat_pay_info'] : []);

        $externalOrderNo = $this->firstString([
            $payload['external_order_no'] ?? null,
            $payload['OutTradeNo'] ?? null,
            $payload['out_trade_no'] ?? null,
            $payload['order_id'] ?? null,
            $orderInfo['order_id'] ?? null,
        ]);
        $orderNo = $this->firstString([$payload['order_no'] ?? null]);
        if ($orderNo === '' && $externalOrderNo !== '') {
            $orderNo = trim((string) DB::table('orders')
                ->where('provider', self::PROVIDER)
                ->where('external_trade_no', $externalOrderNo)
                ->value('order_no'));
        }

        $status = (int) ($payload['provider_status'] ?? $orderInfo['status'] ?? 2);
        $eventType = $this->eventType($payload, $status);
        $providerTradeNo = $this->firstString([
            $payload['provider_trade_no'] ?? null,
            $payload['wx_order_id'] ?? null,
            $orderInfo['wx_order_id'] ?? null,
            $wechatPayInfo['TransactionId'] ?? null,
            $wechatPayInfo['transaction_id'] ?? null,
        ]);
        $providerEventId = $this->firstString([$payload['provider_event_id'] ?? null]);
        if ($providerEventId === '') {
            $providerEventId = $eventType.':'.($providerTradeNo !== '' ? $providerTradeNo : $externalOrderNo);
        }

        $amount = $this->firstInt([
            $payload['amount_cents'] ?? null,
            $payload['paid_fee'] ?? null,
            $orderInfo['paid_fee'] ?? null,
            $payload['order_fee'] ?? null,
            $orderInfo['order_fee'] ?? null,
            $goodsInfo['ActualPrice'] ?? null,
            $goodsInfo['actual_price'] ?? null,
        ]);
        $refundAmount = $this->firstInt([
            $payload['refund_amount_cents'] ?? null,
            $payload['refund_fee'] ?? null,
            $orderInfo['refund_fee'] ?? null,
        ]);
        $paidAt = $this->normalizeTimestamp($this->firstString([
            $payload['paid_at'] ?? null,
            $payload['paid_time'] ?? null,
            $orderInfo['paid_time'] ?? null,
            $wechatPayInfo['PaidTime'] ?? null,
            $wechatPayInfo['paid_time'] ?? null,
        ]));

        return [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => $externalOrderNo !== '' ? $externalOrderNo : null,
            'provider_trade_no' => $providerTradeNo !== '' ? $providerTradeNo : null,
            'paid_at' => $paidAt,
            'amount_cents' => $amount,
            'currency' => 'CNY',
            'event_type' => $eventType,
            'refund_amount_cents' => $refundAmount,
            'refund_reason' => $this->firstString([$payload['refund_reason'] ?? null]) ?: null,
        ];
    }

    private function eventType(array $payload, int $status): string
    {
        $explicit = strtolower($this->firstString([$payload['event_type'] ?? null]));
        if ($explicit !== '') {
            return $explicit;
        }

        return match ($status) {
            2, 3, 4 => 'payment_succeeded',
            5, 8, 9, 10 => 'refund_succeeded',
            6 => 'trade_closed',
            7 => 'refund_failed',
            default => 'payment_pending',
        };
    }

    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function firstInt(array $values): int
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return 0;
    }

    private function normalizeTimestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return $value;
    }
}
