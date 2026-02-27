<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use Yansongda\Pay\Pay;

class AlipayCheckoutService
{
    /**
     * @return array<string,mixed>
     */
    public function createCheckoutAction(string $orderNo, string $userAgent): array
    {
        $scene = $this->isMobileUserAgent($userAgent) ? 'mobile' : 'desktop';
        $launchUrl = url('/api/v0.3/orders/'.rawurlencode($orderNo).'/pay/alipay?scene='.$scene);

        return [
            'ok' => true,
            'type' => $scene === 'mobile' ? 'redirect' : 'html',
            'value' => $launchUrl,
        ];
    }

    /**
     * @param  array<string,mixed>  $order
     */
    public function launch(array $order, string $scene = 'desktop'): mixed
    {
        if (! class_exists(Pay::class)) {
            return null;
        }

        Pay::config(config('pay'));

        $notifyUrl = trim((string) data_get(config('pay.alipay.default', []), 'notify_url', ''));
        if ($notifyUrl === '') {
            $notifyUrl = url('/api/v0.3/webhooks/payment/alipay');
        }
        $returnUrl = trim((string) data_get(config('pay.alipay.default', []), 'return_url', ''));

        $amountCents = max(0, (int) ($order['amount_cents'] ?? 0));
        $totalAmount = number_format($amountCents / 100, 2, '.', '');

        $payload = [
            'out_trade_no' => (string) ($order['order_no'] ?? ''),
            'total_amount' => $totalAmount,
            'subject' => (string) ($order['sku'] ?? 'FermatMind Order'),
            'notify_url' => $notifyUrl,
        ];
        if ($returnUrl !== '') {
            $payload['return_url'] = $returnUrl;
        }

        $scene = strtolower(trim($scene));
        if ($scene === 'mobile') {
            return Pay::alipay()->h5($payload);
        }

        return Pay::alipay()->web($payload);
    }

    private function isMobileUserAgent(string $userAgent): bool
    {
        return preg_match('/android|iphone|ipad|ipod|mobile|micromessenger/i', $userAgent) === 1;
    }
}
