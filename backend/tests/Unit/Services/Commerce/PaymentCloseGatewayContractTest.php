<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\Compensation\Gateways\BillingLifecycleGateway;
use App\Services\Commerce\Compensation\Gateways\WechatPayLifecycleGateway;
use Tests\TestCase;

final class PaymentCloseGatewayContractTest extends TestCase
{
    public function test_wechatpay_close_returns_closed_summary(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $gateway = new class extends WechatPayLifecycleGateway
        {
            protected function dispatchClose(array $order): array
            {
                return [
                    'trade_state' => 'CLOSED',
                    'transaction_id' => 'wx_closed_contract_1',
                ];
            }
        };

        $result = $gateway->closePayment([
            'order_no' => 'ord_close_contract_wechat_1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['supported']);
        $this->assertSame('closed', $result['status']);
        $this->assertSame('wx_closed_contract_1', $result['provider_trade_no']);
        $this->assertTrue($result['is_terminal']);
        $this->assertTrue($result['supports_close']);
    }

    public function test_billing_close_explicitly_reports_unsupported(): void
    {
        $result = (new BillingLifecycleGateway)->closePayment([
            'order_no' => 'ord_close_contract_billing_1',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['supported']);
        $this->assertSame('unsupported', $result['status']);
        $this->assertFalse($result['supports_close']);
    }
}
