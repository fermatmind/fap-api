<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation\Gateways;

use App\Services\Commerce\Compensation\Contracts\PaymentLifecycleGatewayInterface;
use Illuminate\Support\Arr;
use Yansongda\Pay\Pay;

class AlipayLifecycleGateway implements PaymentLifecycleGatewayInterface
{
    public function provider(): string
    {
        return 'alipay';
    }

    public function queryPaymentStatus(array $context): array
    {
        $queriedAt = now()->toIso8601String();
        if (! $this->isConfigured()) {
            return $this->unsupportedQuery($queriedAt, 'alipay query not configured.');
        }

        $queryOrder = $this->buildQueryOrder($context);
        if ($queryOrder === []) {
            return $this->unsupportedQuery($queriedAt, 'alipay query missing trade reference.');
        }

        try {
            $payload = $this->dispatchQuery($queryOrder);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'supported' => true,
                'status' => 'unknown',
                'provider_trade_no' => null,
                'paid_at' => null,
                'queried_at' => $queriedAt,
                'raw_state' => null,
                'is_terminal' => false,
                'supports_close' => true,
                'reason' => mb_substr($e->getMessage(), 0, 255),
            ];
        }

        $rawState = strtoupper(trim((string) Arr::get($payload, 'trade_status', Arr::get($payload, 'status', ''))));
        $status = match ($rawState) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => 'paid',
            'TRADE_CLOSED' => 'canceled',
            'WAIT_BUYER_PAY' => 'pending',
            default => 'unknown',
        };

        return [
            'ok' => $status !== 'unknown',
            'supported' => true,
            'status' => $status,
            'provider_trade_no' => $this->trimOrNull((string) Arr::get($payload, 'trade_no', '')),
            'paid_at' => $this->trimOrNull((string) Arr::get($payload, 'gmt_payment', Arr::get($payload, 'paid_at', ''))),
            'queried_at' => $queriedAt,
            'raw_state' => $rawState !== '' ? $rawState : null,
            'is_terminal' => in_array($status, ['paid', 'canceled'], true),
            'supports_close' => true,
            'reason' => $status === 'unknown' ? 'alipay query returned unknown trade status.' : null,
        ];
    }

    public function closePayment(array $context): array
    {
        $closedAt = now()->toIso8601String();
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'supported' => false,
                'status' => 'unsupported',
                'provider_trade_no' => null,
                'closed_at' => null,
                'raw_state' => null,
                'is_terminal' => false,
                'supports_close' => false,
                'reason' => 'alipay close not configured.',
            ];
        }

        $queryOrder = $this->buildQueryOrder($context);
        if ($queryOrder === []) {
            return [
                'ok' => false,
                'supported' => true,
                'status' => 'unknown',
                'provider_trade_no' => null,
                'closed_at' => null,
                'raw_state' => null,
                'is_terminal' => false,
                'supports_close' => true,
                'reason' => 'alipay close missing trade reference.',
            ];
        }

        try {
            $payload = $this->dispatchClose($queryOrder);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'supported' => true,
                'status' => 'unknown',
                'provider_trade_no' => null,
                'closed_at' => null,
                'raw_state' => null,
                'is_terminal' => false,
                'supports_close' => true,
                'reason' => mb_substr($e->getMessage(), 0, 255),
            ];
        }

        $rawState = strtoupper(trim((string) Arr::get($payload, 'trade_status', 'TRADE_CLOSED')));

        return [
            'ok' => true,
            'supported' => true,
            'status' => 'closed',
            'provider_trade_no' => $this->trimOrNull((string) Arr::get($payload, 'trade_no', '')),
            'closed_at' => $closedAt,
            'raw_state' => $rawState !== '' ? $rawState : 'TRADE_CLOSED',
            'is_terminal' => true,
            'supports_close' => true,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $order
     * @return array<string,mixed>
     */
    protected function dispatchQuery(array $order): array
    {
        Pay::config(config('pay'));

        return $this->responseToArray(Pay::alipay()->query($order));
    }

    /**
     * @param  array<string,mixed>  $order
     * @return array<string,mixed>
     */
    protected function dispatchClose(array $order): array
    {
        Pay::config(config('pay'));

        return $this->responseToArray(Pay::alipay()->close($order));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function buildQueryOrder(array $context): array
    {
        $order = [];

        $providerTradeNo = $this->trimOrNull((string) ($context['provider_trade_no'] ?? ''));
        if ($providerTradeNo !== null) {
            $order['trade_no'] = $providerTradeNo;
        }

        $orderNo = $this->trimOrNull((string) ($context['order_no'] ?? ''));
        if ($orderNo !== null) {
            $order['out_trade_no'] = $orderNo;
        }

        return $order;
    }

    private function isConfigured(): bool
    {
        if (! class_exists(Pay::class)) {
            return false;
        }

        $default = config('pay.alipay.default', []);
        if (! is_array($default)) {
            return false;
        }

        return trim((string) ($default['app_id'] ?? '')) !== '';
    }

    /**
     * @return array<string,mixed>
     */
    private function responseToArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if ($response instanceof \JsonSerializable) {
            $serialized = $response->jsonSerialize();
            if (is_array($serialized)) {
                return $serialized;
            }
        }

        if (is_object($response)) {
            if (method_exists($response, 'all')) {
                $all = $response->all();
                if (is_array($all)) {
                    return $all;
                }
            }

            if (method_exists($response, 'toArray')) {
                $array = $response->toArray();
                if (is_array($array)) {
                    return $array;
                }
            }
        }

        return [];
    }

    private function unsupportedQuery(string $queriedAt, string $reason): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'status' => 'unsupported',
            'provider_trade_no' => null,
            'paid_at' => null,
            'queried_at' => $queriedAt,
            'raw_state' => null,
            'is_terminal' => false,
            'supports_close' => false,
            'reason' => $reason,
        ];
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
