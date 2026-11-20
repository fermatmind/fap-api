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
    public function reconcileAlipayRefund(object $order, string $refundNo): void
    {
        $trade = (string) ($order->provider_trade_no ?? '');
        if ((int) $order->org_id !== 0 || $order->provider !== 'alipay' || $order->currency !== 'CNY'
            || (CareerAssessmentPurchase::contract((string) $order->sku)['currency'] ?? null) !== 'CNY'
            || (int) $order->amount_cents !== CareerAssessmentPurchase::PRICE
            || ! in_array(Order::normalizePaymentState($order->payment_state, $order->status), [Order::PAYMENT_STATE_PAID, Order::PAYMENT_STATE_REFUNDED], true)
            || $trade === '' || trim($refundNo) === '' || strlen($refundNo) > 64) {
            throw new RuntimeException('A paid career assessment order and refund reference are required.');
        }

        $result = app(AlipayCheckoutService::class)->queryRefund($order->order_no, $trade, $refundNo);
        $amount = WaffoGateway::cents($result['refund_amount'] ?? null);
        if (($result['code'] ?? '') !== '10000' || ($result['refund_status'] ?? '') !== 'REFUND_SUCCESS'
            || ($result['out_trade_no'] ?? '') !== $order->order_no || ($result['trade_no'] ?? '') !== $trade
            || ($result['out_request_no'] ?? '') !== $refundNo
            || WaffoGateway::cents($result['total_amount'] ?? null) !== CareerAssessmentPurchase::PRICE
            || $amount <= 0 || $amount > CareerAssessmentPurchase::PRICE
            || ($result['currency'] ?? 'CNY') !== 'CNY') {
            throw new RuntimeException('Alipay did not verify the career assessment refund.');
        }

        $record = app(PaymentWebhookProcessor::class)->process('alipay', [
            'out_trade_no' => $order->order_no,
            'trade_no' => $trade,
            'provider_event_id' => 'career94-refund-query-'.hash('sha256', $order->order_no."\n".$refundNo),
            'event_type' => 'refund.succeeded',
            'amount_cents' => CareerAssessmentPurchase::PRICE,
            'refund_amount_cents' => $amount,
            'refund_reason' => 'Verified Alipay merchant refund',
            'currency' => 'CNY',
        ]);
        if (! ($record['ok'] ?? false)) {
            throw new RuntimeException('Could not record the verified Alipay career assessment refund.');
        }
    }

    public function reconcileWaffoRefund(object $order): bool
    {
        if ((int) $order->org_id !== 0 || $order->provider !== 'waffo'
            || (CareerAssessmentPurchase::contract((string) $order->sku)['currency'] ?? null) !== $order->currency
            || (int) $order->amount_cents !== CareerAssessmentPurchase::PRICE) {
            throw new RuntimeException('Invalid career refund order.');
        }
        $reference = 'career94-'.substr(hash('sha256', $order->order_no), 0, 32);
        $client = app(WaffoTestClient::class);
        $payment = $client->findPayment($order->provider_trade_no);
        $refund = $client->findRefund($reference);
        if (! $refund || ! in_array($refund['status'] ?? '', ['pending', 'succeeded'], true)
            || ($refund['refundTicketMerchantExternalId'] ?? '') !== $reference
            || ($refund['paymentId'] ?? '') !== ($payment['id'] ?? null)
            || ($payment['orderId'] ?? '') !== $order->provider_trade_no
            || ($payment['snapshotAmountDetails']['currency'] ?? '') !== $order->currency
            || WaffoGateway::cents($payment['snapshotAmountDetails']['total'] ?? null) !== CareerAssessmentPurchase::PRICE
            || ($refund['orderMerchantExternalId'] ?? '') !== $order->order_no
            || ($refund['testMode'] ?? null) !== (config('payments.waffo.environment') === 'test')
            || ($refund['amount']['currency'] ?? '') !== $order->currency
            || WaffoGateway::cents($refund['amount']['amount'] ?? null) !== CareerAssessmentPurchase::PRICE) {
            throw new RuntimeException('Waffo has not confirmed the exact refund.');
        }
        if ($refund['status'] === 'pending') {
            return false;
        }
        $result = app(PaymentWebhookProcessor::class)->process('waffo', [
            'mode' => config('payments.waffo.environment'), 'storeId' => config('payments.waffo.store_id'),
            'eventId' => 'career94-refund-query-'.hash('sha256', $reference),
            'eventType' => 'refund.succeeded', 'timestamp' => now()->toIso8601String(),
            'data' => ['orderId' => $order->provider_trade_no, 'orderMerchantExternalId' => $order->order_no,
                'currency' => $order->currency, 'refundStatus' => 'succeeded', 'refundedAmount' => '2.99',
                'originalPayment' => ['total' => '2.99'],
                'orderMetadata' => ['fap_order_no' => $order->order_no, 'fap_attempt_id' => $order->target_attempt_id,
                    'fap_product_id' => config('payments.waffo.career94_product_id')]],
        ]);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException('Could not record the Waffo refund.');
        }

        return true;
    }

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
            || (CareerAssessmentPurchase::contract((string) $order->sku)['currency'] ?? null) !== $currency) {
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
