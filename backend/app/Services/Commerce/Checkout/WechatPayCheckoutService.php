<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Yansongda\Pay\Pay;

use function data_get;
use function url;

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

        $outTradeNo = $this->resolveOutTradeNo($orderNo);
        $this->persistExternalTradeNo($orderNo, $outTradeNo);

        Pay::config(config('pay'));

        $order = [
            'out_trade_no' => $outTradeNo,
            'description' => $description,
            'amount' => [
                'total' => $amountCents,
                'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY',
            ],
            'notify_url' => $notifyUrl,
            'attach' => $orderNo,
        ];

        $appId = $this->resolveWechatAppId();
        if ($appId !== '') {
            $order['appid'] = $appId;
        }

        $isMobile = $this->isMobileUserAgent($userAgent);

        try {
            if ($isMobile) {
                $response = Pay::wechat()->h5($order);
                $payload = $this->responseToArray($response);
                $url = trim((string) ($payload['h5_url'] ?? $payload['url'] ?? ''));
                if ($url === '') {
                    Log::error('WECHAT_H5_URL_MISSING', [
                        'order_no' => $orderNo,
                        'out_trade_no' => $outTradeNo,
                        'appid' => $appId !== '' ? $appId : null,
                        'amount_cents' => $amountCents,
                        'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY',
                        'payload' => $payload,
                    ]);

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
                Log::error('WECHAT_CODE_URL_MISSING', [
                    'order_no' => $orderNo,
                    'out_trade_no' => $outTradeNo,
                    'appid' => $appId !== '' ? $appId : null,
                    'amount_cents' => $amountCents,
                    'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY',
                    'payload' => $payload,
                ]);

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
            Log::error('WECHAT_CHECKOUT_FAILED', [
                'order_no' => $orderNo,
                'out_trade_no' => $outTradeNo,
                'mode' => $isMobile ? 'h5' : 'scan',
                'appid' => $appId !== '' ? $appId : null,
                'amount_cents' => $amountCents,
                'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY',
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'previous_class' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
                'previous_message' => $e->getPrevious()?->getMessage(),
                'trace_top' => array_slice($e->getTrace(), 0, 5),
            ]);

            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_ERROR',
                'message' => 'wechat checkout failed.',
                'details' => $e->getMessage(),
            ];
        }
    }

    private function resolveWechatAppId(): string
    {
        $default = config('pay.wechat.default', []);

        $mpAppId = trim((string) data_get($default, 'mp_app_id', ''));
        if ($mpAppId !== '') {
            return $mpAppId;
        }

        $appId = trim((string) data_get($default, 'app_id', ''));
        if ($appId !== '') {
            return $appId;
        }

        return trim((string) data_get($default, 'mini_app_id', ''));
    }

    private function resolveOutTradeNo(string $orderNo): string
    {
        $orderNo = trim($orderNo);

        $persisted = $this->resolvePersistedExternalTradeNo($orderNo);
        if ($persisted !== '') {
            return $persisted;
        }

        if (preg_match('/^ord_([0-9a-fA-F]{8})-([0-9a-fA-F]{4})-([0-9a-fA-F]{4})-([0-9a-fA-F]{4})-([0-9a-fA-F]{12})$/', $orderNo, $m) === 1) {
            return strtolower($m[1].$m[2].$m[3].$m[4].$m[5]);
        }

        return 'wx'.substr(hash('sha256', $orderNo), 0, 30);
    }

    private function resolvePersistedExternalTradeNo(string $orderNo): string
    {
        if ($orderNo === '') {
            return '';
        }

        try {
            $existing = trim((string) DB::table('orders')
                ->where('order_no', $orderNo)
                ->value('external_trade_no'));

            if ($existing !== '' && strlen($existing) <= 32) {
                return $existing;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return '';
    }

    private function persistExternalTradeNo(string $orderNo, string $outTradeNo): void
    {
        $orderNo = trim($orderNo);
        $outTradeNo = trim($outTradeNo);

        if ($orderNo === '' || $outTradeNo === '') {
            return;
        }

        try {
            DB::table('orders')
                ->where('order_no', $orderNo)
                ->where(function ($query): void {
                    $query->whereNull('external_trade_no')
                        ->orWhere('external_trade_no', '');
                })
                ->update([
                    'external_trade_no' => $outTradeNo,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            report($e);
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
