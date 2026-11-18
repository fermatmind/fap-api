<?php

declare(strict_types=1);

namespace App\Services\Commerce\PaymentGateway;

use App\Services\Commerce\CareerAssessmentPurchase;
use App\Services\Payments\PaymentProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WaffoGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'waffo';
    }

    public function verifySignature(Request $request): bool
    {
        if (! app(PaymentProviderRegistry::class)->isEnabled('waffo')) {
            return false;
        }
        $header = (string) $request->header('X-Waffo-Signature', '');
        if (! preg_match('/^t=(\d{13}),\s*v1=([A-Za-z0-9+\/=]+)$/D', $header, $matches)) {
            return false;
        }
        // Waffo retries retain the original signature for 31+ minutes.
        // Replay protection is the existing provider/event identity transaction.
        $age = (int) floor(microtime(true) * 1000) - (int) $matches[1];
        if ($age > 45 * 60 * 1000 || $age < -60 * 1000) {
            return false;
        }
        $signature = base64_decode($matches[2], true);
        $key = (string) config('payments.waffo.webhook_public_key', '');
        if ($signature === false || $key === '') {
            return false;
        }

        return openssl_verify($matches[1].'.'.$request->getContent(), $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function normalizePayload(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $meta = is_array($data) ? ($data['orderMetadata'] ?? []) : [];
        $event = $payload['eventType'] ?? '';
        $mode = config('payments.waffo.environment', 'test');
        if (! is_array($data) || ! is_array($meta)
            || ($payload['mode'] ?? '') !== $mode
            || ($payload['storeId'] ?? '') !== config('payments.waffo.store_id')
            || ! in_array($event, ['order.completed', 'refund.succeeded'], true)
            || ! is_string($data['orderMerchantExternalId'] ?? null)
            || ($meta['fap_order_no'] ?? '') !== $data['orderMerchantExternalId']
            || ! is_string($data['orderId'] ?? null) || $data['orderId'] === ''
            || ! is_string($payload['eventId'] ?? null) || $payload['eventId'] === '') {
            return [];
        }

        $product = $meta['fap_product_id'] ?? '';
        $currency = $data['currency'] ?? '';
        $careerProduct = config('payments.waffo.career94_product_id');
        $career = is_string($careerProduct) && $careerProduct !== '' && $product === $careerProduct;
        if ($career) {
            $sku = is_string($currency) ? (CareerAssessmentPurchase::SKUS[$currency] ?? null) : null;
            $attemptId = $meta['fap_attempt_id'] ?? null;
            if ($sku === null || ! is_string($attemptId) || ! DB::table('orders')
                ->where('order_no', $data['orderMerchantExternalId'])->where('org_id', 0)->where('provider', 'waffo')
                ->where('sku', $sku)->where('currency', $currency)->where('amount_cents', CareerAssessmentPurchase::PRICE)
                ->where('target_attempt_id', $attemptId)->exists()) {
                return [];
            }
        } elseif ($mode !== 'test' || $product === '' || $product !== config('payments.waffo.product_id') || $currency !== 'USD') {
            return [];
        }

        $refund = $event === 'refund.succeeded';
        $amount = self::cents($refund ? ($data['originalPayment']['total'] ?? null) : ($data['chargedAmount'] ?? null));
        $refundAmount = $refund ? self::cents($data['refundedAmount'] ?? null) : 0;
        if ($amount === null || $amount <= 0 || ($career && $amount !== CareerAssessmentPurchase::PRICE)
            || ($refund && ($refundAmount !== $amount || ($data['refundStatus'] ?? '') !== 'succeeded'))
            || (! $refund && (($data['paymentStatus'] ?? '') !== 'succeeded' || ($data['orderStatus'] ?? '') !== 'completed'))) {
            return [];
        }

        return [
            'provider_event_id' => $event.':'.$payload['eventId'],
            'order_no' => (string) $data['orderMerchantExternalId'],
            'external_trade_no' => $data['orderId'] ?? null,
            'paid_at' => $payload['timestamp'] ?? null,
            'amount_cents' => $amount,
            'currency' => $currency,
            'event_type' => $event,
            'refund_amount_cents' => $refundAmount,
            'refund_reason' => $refund ? 'Waffo verified full refund' : null,
        ];
    }

    public static function cents(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9]\d{0,7})(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
