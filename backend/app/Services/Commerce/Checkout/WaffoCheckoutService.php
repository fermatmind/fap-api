<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use Illuminate\Support\Facades\DB;

final class WaffoCheckoutService
{
    public function createCheckout(string $orderNo, ?string $attemptId, int $amountCents, string $currency): array
    {
        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $attempt = $attemptId ? DB::table('attempts')->where('id', $attemptId)->first() : null;
        if (! $order || ! $attempt || $order->provider !== 'waffo'
            || $attempt->scale_code !== 'MBTI' || $order->target_attempt_id !== $attemptId
            || (int) $order->amount_cents !== 199 || $order->currency !== 'USD'
            || $amountCents !== 199 || $currency !== 'USD') {
            return $this->unavailable();
        }
        $productId = (string) config('payments.waffo.product_id', '');
        if ($productId === '') {
            return $this->unavailable();
        }
        $locale = strtolower((string) ($attempt->locale ?? 'en'));
        $language = in_array($locale, ['zh-tw', 'zh-hant'], true) ? 'zh-Hant-TW' : (str_starts_with($locale, 'zh') ? 'zh-Hans' : 'en');
        $waitUrl = app(\App\Services\Commerce\OrderManager::class)->presentPaymentRecoveryUrls($order, null, $attempt->locale)['wait_url'];
        if (! is_string($waitUrl) || parse_url($waitUrl, PHP_URL_SCHEME) !== 'https') {
            return $this->unavailable();
        }
        try {
            $data = app(WaffoTestClient::class)->createSession([
                'productId' => $productId,
                'currency' => 'USD',
                'successUrl' => $waitUrl,
                'priceSnapshot' => ['amount' => '1.99', 'taxIncluded' => true, 'taxCategory' => 'digital_goods'],
                'orderMerchantExternalId' => $orderNo,
                'metadata' => ['fap_order_no' => $orderNo, 'fap_attempt_id' => $attemptId, 'fap_product_id' => $productId],
                'language' => $language,
                'expiresInSeconds' => 1800,
                'allowPromotionCodes' => false,
            ]);
        } catch (\Throwable) {
            return $this->unavailable();
        }
        $url = $data['checkoutUrl'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', ['pancake.waffo.ai', 'checkout.waffo.ai'], true)
            || ! preg_match('~^(?:/store)?/[A-Za-z0-9-]+/checkout/cs_[A-Za-z0-9_-]+$~D', $parts['path'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! is_string($data['sessionId'] ?? null)) {
            return $this->unavailable();
        }

        return ['ok' => true, 'type' => 'redirect', 'value' => $url, 'provider_session_ref' => $data['sessionId']];
    }

    private function unavailable(): array
    {
        return ['ok' => false, 'status' => 422, 'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'message' => 'Waffo test checkout unavailable.'];
    }
}
