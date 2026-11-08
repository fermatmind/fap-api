<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use App\Services\Payments\PaymentProviderRegistry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WaffoTestClient
{
    public function createSession(array $payload): array
    {
        return $this->post('/v1/actions/checkout/create-session', $payload);
    }

    public function findOrder(string $orderNo): ?array
    {
        $data = $this->post('/v1/graphql', [
            'query' => 'query($storeId: String!, $ref: String!) { onetimeOrders(storeId: $storeId, filter: { orderMerchantExternalId: { eq: $ref } }, limit: 2) { id status orderMerchantExternalId currency priceSnapshot { total currency } metadata } }',
            'variables' => ['storeId' => config('payments.waffo.store_id'), 'ref' => $orderNo],
        ]);
        $rows = $data['onetimeOrders'] ?? null;
        if (! is_array($rows) || count($rows) > 1) {
            throw new RuntimeException('Waffo order query is ambiguous.');
        }

        $order = $rows[0] ?? null;
        if (is_array($order) && is_string($order['metadata'] ?? null)) {
            // Waffo's GraphQL JSON scalar currently serializes metadata as JSON text.
            $order['metadata'] = json_decode($order['metadata'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $order;
    }

    public function findPayment(string $orderId): ?array
    {
        $data = $this->post('/v1/graphql', [
            'query' => 'query($id: String!) { payments(filter: { orderId: { eq: $id }, status: { eq: "succeeded" } }, limit: 2) { id orderId status refundStatus snapshotAmountDetails { total currency } } }',
            'variables' => ['id' => $orderId],
        ]);
        $rows = $data['payments'] ?? null;
        if (! is_array($rows) || count($rows) !== 1) {
            throw new RuntimeException('Waffo successful payment is ambiguous.');
        }

        return $rows[0];
    }

    public function requestFullRefund(string $paymentId, string $reference): array
    {
        return $this->post('/v1/actions/refund-ticket/create-ticket', [
            'paymentId' => $paymentId,
            'reason' => 'MBTI sandbox full refund',
            'requestedAmount' => ['amount' => '1.99', 'currency' => 'USD'],
            'refundTicketMerchantExternalId' => $reference,
        ]);
    }

    private function post(string $path, array $payload): array
    {
        if (! app(PaymentProviderRegistry::class)->isEnabled('waffo')) {
            throw new RuntimeException('Waffo test provider is disabled.');
        }
        $keyPath = (string) config('payments.waffo.private_key_path', '');
        $merchant = (string) config('payments.waffo.merchant_id', '');
        if ($merchant === '' || $keyPath === '' || ! is_readable($keyPath)) {
            throw new RuntimeException('Waffo test credentials are unavailable.');
        }
        $key = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if ($key === false) {
            throw new RuntimeException('Waffo test key is invalid.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $canonical = "POST\n{$path}\n{$timestamp}\n".base64_encode(hash('sha256', $body, true));
        if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Waffo signing failed.');
        }
        try {
            $response = Http::timeout(20)->withoutRedirecting()->withHeaders([
                'X-Merchant-Id' => $merchant,
                'X-Timestamp' => $timestamp,
                'X-Signature' => base64_encode($signature),
            ])->withBody($body, 'application/json')->post('https://api.waffo.ai'.$path);
        } catch (\Throwable) {
            throw new RuntimeException('Waffo test request failed.');
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! empty($json['errors'])) {
            throw new RuntimeException('Waffo test response rejected.');
        }
        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Waffo test response is incomplete.');
        }

        return $data;
    }
}
