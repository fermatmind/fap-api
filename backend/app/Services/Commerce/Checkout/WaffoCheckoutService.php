<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use App\Services\Commerce\CareerAssessmentPurchase;
use Illuminate\Support\Facades\DB;

final class WaffoCheckoutService
{
    public function createCheckout(string $orderNo, ?string $attemptId, int $amountCents, string $currency): array
    {
        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $attempt = $attemptId ? DB::table('attempts')->where('id', $attemptId)->first() : null;
        $contract = $order ? CareerAssessmentPurchase::contract((string) $order->sku) : null;
        $career = $contract !== null && $attempt?->scale_code === $contract['scale'];
        $sandboxMbti = config('payments.waffo.environment') === 'test'
            && $attempt?->scale_code === 'MBTI' && $amountCents === 199 && $currency === 'USD';
        $careerContract = $career && $amountCents === CareerAssessmentPurchase::PRICE
            && ($contract['currency'] ?? null) === $currency
            && ($contract['scale'] === 'RIASEC' || ($attempt->region === 'CN_MAINLAND' ? $currency === 'CNY' : $currency === 'USD'));
        if (! $order || ! $attempt || $order->provider !== 'waffo'
            || (! $sandboxMbti && ! $careerContract) || $order->target_attempt_id !== $attemptId
            || (int) $order->amount_cents !== $amountCents || $order->currency !== $currency
            || ! in_array(\App\Models\Order::normalizePaymentState($order->payment_state ?? '', $order->status ?? ''), ['created', 'pending'], true)) {
            return $this->unavailable();
        }
        $productId = (string) config($career ? 'payments.waffo.career94_product_id' : 'payments.waffo.product_id', '');
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
            $client = app(WaffoTestClient::class);
            // An existing platform order may be processing or paid despite a lost callback.
            // Reconcile it instead of starting a second payment.
            if ($client->findOrder($orderNo) !== null) {
                return $this->unavailable();
            }
            $buyerEmail = $career && (int) $order->org_id === 0 && $order->user_id === $attempt->user_id
                ? DB::table('users')->where('id', $order->user_id)->whereNotNull('email_verified_at')->value('email')
                : null;
            $data = $client->createSession([
                'productId' => $productId,
                'currency' => $currency,
                'successUrl' => $waitUrl,
                'priceSnapshot' => ['amount' => $career ? '2.99' : '1.99', 'taxIncluded' => true, 'taxCategory' => 'digital_goods'],
                'orderMerchantExternalId' => $orderNo,
                'metadata' => ['fap_order_no' => $orderNo, 'fap_attempt_id' => $attemptId, 'fap_product_id' => $productId],
                'language' => $language,
                'expiresInSeconds' => 1800,
                'allowPromotionCodes' => false,
                ...(is_string($buyerEmail) && filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) ? ['buyerEmail' => $buyerEmail] : []),
                ...($career && $currency === 'CNY' ? ['billingDetail' => ['country' => 'CN', 'isBusiness' => false]] : []),
                ...($career ? ['includePaymentMethods' => $currency === 'CNY'
                    ? ['wechat'] : ['card', 'applepay', 'googlepay']] : []),
            ], ($career ? 'career94-checkout-' : 'mbti-checkout-').hash('sha256', $orderNo));
            if (! is_string($data['expiresAt'] ?? null) || \Illuminate\Support\Carbon::parse($data['expiresAt'])->isPast()) {
                return $this->unavailable();
            }
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

        return ['ok' => true, 'type' => 'redirect', 'value' => $url, 'provider_session_ref' => $data['sessionId'], 'expires_at' => $data['expiresAt']];
    }

    private function unavailable(): array
    {
        return ['ok' => false, 'status' => 422, 'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'message' => 'Waffo test checkout unavailable.'];
    }
}
