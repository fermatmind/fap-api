<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation\Gateways;

use App\Services\Commerce\Checkout\WaffoTestClient;
use App\Services\Commerce\Compensation\Contracts\PaymentLifecycleGatewayInterface;
use App\Services\Commerce\PaymentGateway\WaffoGateway;

final class WaffoLifecycleGateway implements PaymentLifecycleGatewayInterface
{
    public function provider(): string
    {
        return 'waffo';
    }

    public function queryPaymentStatus(array $context): array
    {
        $result = [
            'ok' => false, 'supported' => true, 'status' => 'unknown',
            'provider_trade_no' => null, 'paid_at' => null, 'queried_at' => now()->toIso8601String(),
            'raw_state' => null, 'is_terminal' => false, 'supports_close' => false,
            'reason' => 'Waffo payment has not been verified.',
        ];
        $reference = trim((string) ($context['order_no'] ?? ''));
        if ($reference === '' || (int) ($context['amount_expected'] ?? 0) !== 199 || ($context['currency'] ?? '') !== 'USD') {
            return $result;
        }
        try {
            $order = app(WaffoTestClient::class)->findOrder($reference);
        } catch (\Throwable) {
            return $result;
        }
        if ($order === null) {
            return $result; // No order may mean the buyer has not submitted the cashier yet.
        }
        $meta = $order['metadata'] ?? [];
        if (($order['orderMerchantExternalId'] ?? '') !== $reference
            || ($meta['fap_order_no'] ?? '') !== $reference
            || ($meta['fap_product_id'] ?? '') !== config('payments.waffo.product_id')
            || ($order['currency'] ?? '') !== 'USD'
            || WaffoGateway::cents($order['priceSnapshot']['total'] ?? null) !== 199) {
            return $result;
        }
        $status = match ($order['status'] ?? '') {
            'completed' => 'paid',
            'canceled' => 'canceled',
            'pending' => 'pending',
            default => 'unknown',
        };

        if ($status === 'paid') {
            try {
                $payment = app(WaffoTestClient::class)->findPayment((string) ($order['id'] ?? ''));
            } catch (\Throwable) {
                return $result;
            }
            if (($payment['orderId'] ?? '') !== ($order['id'] ?? null)
                || ($payment['status'] ?? '') !== 'succeeded' || ($payment['refundStatus'] ?? '') !== 'none'
                || ($payment['snapshotAmountDetails']['currency'] ?? '') !== 'USD'
                || WaffoGateway::cents($payment['snapshotAmountDetails']['total'] ?? null) !== 199) {
                return $result;
            }
        }

        return array_replace($result, [
            'ok' => $status !== 'unknown', 'status' => $status,
            'provider_trade_no' => $order['id'] ?? null,
            'raw_state' => $order['status'] ?? null,
            'is_terminal' => in_array($status, ['paid', 'canceled'], true),
            'reason' => $status === 'unknown' ? $result['reason'] : null,
        ]);
    }

    public function closePayment(array $context): array
    {
        // Merchant credentials cannot call the customer-token cancellation endpoint.
        // Never turn an abandoned/unknown session into a locally "canceled" payment.
        return ['ok' => false, 'supported' => false, 'status' => 'unsupported', 'supports_close' => false,
            'is_terminal' => false, 'provider_trade_no' => null, 'closed_at' => null,
            'reason' => 'Waffo sessions expire at the platform; merchant close is unavailable.'];
    }
}
