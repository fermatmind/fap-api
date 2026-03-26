<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\Compensation\Gateways\AlipayLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\BillingLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\WechatPayLifecycleGateway;
use Tests\TestCase;

final class PaymentQueryGatewayContractTest extends TestCase
{
    public function test_wechatpay_query_maps_paid_response(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $gateway = new class extends WechatPayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_state' => 'SUCCESS',
                    'transaction_id' => 'wx_paid_contract_1',
                    'success_time' => '2026-03-26T12:00:00+08:00',
                ];
            }
        };

        $result = $gateway->queryPaymentStatus([
            'order_no' => 'ord_query_contract_wechat_1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['supported']);
        $this->assertSame('paid', $result['status']);
        $this->assertSame('wx_paid_contract_1', $result['provider_trade_no']);
        $this->assertTrue($result['is_terminal']);
        $this->assertTrue($result['supports_close']);
    }

    public function test_alipay_query_maps_closed_response(): void
    {
        config([
            'pay.alipay.default.app_id' => 'app_test',
        ]);

        $gateway = new class extends AlipayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_status' => 'TRADE_CLOSED',
                    'trade_no' => 'ali_closed_contract_1',
                ];
            }
        };

        $result = $gateway->queryPaymentStatus([
            'order_no' => 'ord_query_contract_alipay_1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['supported']);
        $this->assertSame('canceled', $result['status']);
        $this->assertSame('ali_closed_contract_1', $result['provider_trade_no']);
        $this->assertTrue($result['is_terminal']);
    }

    public function test_billing_query_explicitly_reports_unsupported(): void
    {
        $result = (new BillingLifecycleGateway)->queryPaymentStatus([
            'order_no' => 'ord_query_contract_billing_1',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['supported']);
        $this->assertSame('unsupported', $result['status']);
        $this->assertFalse($result['supports_close']);
    }
}
