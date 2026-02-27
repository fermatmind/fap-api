<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use Yansongda\Pay\Pay;

class WechatPayCheckoutService
{
    /**
     * @return array<string,mixed>
     */
    public function createCheckoutAction(
        string $orderNo,
        string $description,
        int $amountCents,
        string $currency,
        string $userAgent
    ): array {
        if (! class_exists(Pay::class)) {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_NOT_INSTALLED',
                'message' => 'wechatpay sdk is not installed.',
            ];
        }

        $notifyUrl = trim((string) data_get(config('pay.wechat.default', []), 'notify_url', ''));
        if ($notifyUrl === '') {
            $notifyUrl = url('/api/v0.3/webhooks/payment/wechatpay');
        }

        Pay::config(config('pay'));

        $order = [
            'out_trade_no' => $orderNo,
            'description' => $description,
            'amount' => [
                'total' => $amountCents,
                'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY',
            ],
            'notify_url' => $notifyUrl,
        ];

        try {
            if ($this->isMobileUserAgent($userAgent)) {
                $response = Pay::wechat()->h5($order);
                $payload = $this->responseToArray($response);
                $url = trim((string) ($payload['h5_url'] ?? $payload['url'] ?? ''));
                if ($url === '') {
                    return [
                        'ok' => false,
                        'error_code' => 'PAYMENT_PROVIDER_ERROR',
                        'message' => 'wechat h5 url missing.',
                        'details' => $payload,
                    ];
                }

                return [
                    'ok' => true,
                    'type' => 'redirect',
                    'value' => $url,
                ];
            }

            $response = Pay::wechat()->scan($order);
            $payload = $this->responseToArray($response);
            $url = trim((string) ($payload['code_url'] ?? $payload['url'] ?? ''));
            if ($url === '') {
                return [
                    'ok' => false,
                    'error_code' => 'PAYMENT_PROVIDER_ERROR',
                    'message' => 'wechat code_url missing.',
                    'details' => $payload,
                ];
            }

            return [
                'ok' => true,
                'type' => 'qr',
                'value' => $url,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_ERROR',
                'message' => 'wechat checkout failed.',
                'details' => $e->getMessage(),
            ];
        }
    }

    private function isMobileUserAgent(string $userAgent): bool
    {
        return preg_match('/android|iphone|ipad|ipod|mobile|micromessenger/i', $userAgent) === 1;
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
            if (method_exists($response, 'toArray')) {
                $arr = $response->toArray();
                if (is_array($arr)) {
                    return $arr;
                }
            }

            if (method_exists($response, 'all')) {
                $arr = $response->all();
                if (is_array($arr)) {
                    return $arr;
                }
            }
        }

        return [];
    }
}
