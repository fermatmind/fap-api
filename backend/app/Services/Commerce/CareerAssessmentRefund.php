<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Order;
use App\Services\Commerce\Checkout\AlipayCheckoutService;
use App\Services\Commerce\Checkout\WaffoTestClient;
use App\Services\Commerce\PaymentGateway\WaffoGateway;
use RuntimeException;

final class CareerAssessmentRefund
{
    public function submit(object $order, string $reason): void
    {
        $state = Order::normalizePaymentState($order->payment_state, $order->status);
        if ($state === Order::PAYMENT_STATE_REFUNDED) {
            return;
        }

        $currency = $order->currency;
        $trade = (string) ($order->provider_trade_no ?? '');
        if ($state !== Order::PAYMENT_STATE_PAID || $trade === ''
            || (int) $order->amount_cents !== CareerAssessmentPurchase::PRICE
            || (CareerAssessmentPurchase::SKUS[$currency] ?? null) !== $order->sku) {
            throw new RuntimeException('Career report refund requires a confirmed payment.');
        }

        $reference = 'career94-'.substr(hash('sha256', $order->order_no), 0, 32);
        if ($order->provider === 'waffo') {
            $client = app(WaffoTestClient::class);
            $remote = $client->findOrder($order->order_no);
            if (($remote['id'] ?? '') !== $trade || ($remote['currency'] ?? '') !== $currency
                || ($remote['metadata']['fap_product_id'] ?? '') !== config('payments.waffo.career94_product_id')
                || ($remote['metadata']['fap_attempt_id'] ?? '') !== $order->target_attempt_id) {
                throw new RuntimeException('Waffo career report order identity does not match.');
            }
            $payment = $client->findPayment($trade);
            if (($payment['orderId'] ?? '') !== $trade || ($payment['status'] ?? '') !== 'succeeded'
                || ($payment['refundStatus'] ?? '') !== 'none'
                || ($payment['snapshotAmountDetails']['currency'] ?? '') !== $currency
                || WaffoGateway::cents($payment['snapshotAmountDetails']['total'] ?? null) !== CareerAssessmentPurchase::PRICE
                || ! is_string($payment['id'] ?? null) || $payment['id'] === '') {
                throw new RuntimeException('Waffo could not verify the career report payment for refund.');
            }

            // A refund ticket requests funds. Only the signed refund event revokes access.
            $client->requestFullRefund($payment['id'], $reference, '2.99', $currency, 'Career assessment report refund');

            return;
        }

        if ($order->provider !== 'alipay' || $currency !== 'CNY') {
            throw new RuntimeException('Career report refund provider is unavailable.');
        }

        $result = app(AlipayCheckoutService::class)->requestFullRefund($order->order_no, $trade, $reference, '2.99');
        if (($result['code'] ?? '') !== '10000'
            || ($result['out_trade_no'] ?? '') !== $order->order_no || ($result['trade_no'] ?? '') !== $trade
            || WaffoGateway::cents($result['refund_fee'] ?? null) !== CareerAssessmentPurchase::PRICE
            || ! in_array($result['fund_change'] ?? '', ['Y', 'N'], true)) {
            throw new RuntimeException('Alipay did not confirm the full career report refund.');
        }

        $record = app(PaymentWebhookProcessor::class)->process('alipay', [
            'out_trade_no' => $order->order_no,
            'trade_no' => $trade,
            'provider_event_id' => $reference,
            'event_type' => 'refund.succeeded',
            'amount_cents' => CareerAssessmentPurchase::PRICE,
            'refund_amount_cents' => CareerAssessmentPurchase::PRICE,
            'refund_reason' => $reason,
            'currency' => 'CNY',
        ]);
        if (! ($record['ok'] ?? false)) {
            throw new RuntimeException('Could not record the verified Alipay career report refund.');
        }
    }
}
