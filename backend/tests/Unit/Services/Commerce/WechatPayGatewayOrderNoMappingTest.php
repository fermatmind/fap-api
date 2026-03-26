<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Commerce;

use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentGateway\WechatPayGateway;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class WechatPayGatewayOrderNoMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_payload_supports_resource_ciphertext_array(): void
    {
        $gateway = new WechatPayGateway;

        $normalized = $gateway->normalizePayload([
            'id' => 'evt_resource_ciphertext_array_001',
            'resource' => [
                'ciphertext' => [
                    'out_trade_no' => '8300b57f665041c19b48143275669567',
                    'transaction_id' => 'wx_txn_rc_arr_001',
                    'trade_state' => 'SUCCESS',
                    'success_time' => '2026-03-02T00:00:00+00:00',
                    'attach' => 'ord_ff208a15-9c02-4261-b743-eac53dcae934',
                    'amount' => [
                        'total' => 299,
                        'currency' => 'CNY',
                    ],
                ],
            ],
        ]);

        $this->assertSame('ord_ff208a15-9c02-4261-b743-eac53dcae934', $normalized['order_no']);
        $this->assertSame('wx_txn_rc_arr_001', $normalized['external_trade_no']);
        $this->assertSame(299, $normalized['amount_cents']);
        $this->assertSame('payment_succeeded', $normalized['event_type']);
    }

    public function test_normalize_payload_supports_result_ciphertext_json_string(): void
    {
        $gateway = new WechatPayGateway;

        $cipher = json_encode([
            'out_trade_no' => '8300b57f665041c19b48143275669567',
            'transaction_id' => 'wx_txn_rs_json_001',
            'trade_state' => 'SUCCESS',
            'amount' => [
                'total' => 299,
                'currency' => 'CNY',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($cipher);

        $normalized = $gateway->normalizePayload([
            'id' => 'evt_result_ciphertext_json_001',
            'result' => [
                'ciphertext' => $cipher,
            ],
        ]);

        $this->assertSame('ord_8300b57f-6650-41c1-9b48-143275669567', $normalized['order_no']);
        $this->assertSame('wx_txn_rs_json_001', $normalized['external_trade_no']);
        $this->assertSame(299, $normalized['amount_cents']);
        $this->assertSame('payment_succeeded', $normalized['event_type']);
    }

    public function test_normalize_payload_uses_db_lookup_for_non_reversible_out_trade_no(): void
    {
        $this->seedCommerceCatalog();

        $outTradeNo = 'wx'.substr(hash('sha256', 'legacy-order-no-001'), 0, 30);
        $expectedOrderNo = $this->createOrderAndBindExternalTradeNo($outTradeNo);

        $cipher = json_encode([
            'out_trade_no' => $outTradeNo,
            'transaction_id' => 'wx_txn_db_lookup_001',
            'trade_state' => 'SUCCESS',
            'amount' => [
                'total' => 299,
                'currency' => 'CNY',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($cipher);

        $gateway = new WechatPayGateway;
        $normalized = $gateway->normalizePayload([
            'resource' => [
                'ciphertext' => $cipher,
            ],
        ]);

        $this->assertSame($expectedOrderNo, $normalized['order_no']);
        $this->assertSame('wx_txn_db_lookup_001', $normalized['external_trade_no']);
        $this->assertSame(299, $normalized['amount_cents']);
        $this->assertSame('payment_succeeded', $normalized['event_type']);
    }

    private function seedCommerceCatalog(): void
    {
        $this->seed(Pr19CommerceSeeder::class);
    }

    private function createOrderAndBindExternalTradeNo(string $outTradeNo): string
    {
        /** @var OrderManager $orders */
        $orders = app(OrderManager::class);

        $sku = $this->resolveSeededSku();
        $this->assertNotSame('', $sku, 'No seeded sku found.');

        $ref = new ReflectionMethod($orders, 'createOrder');
        $argc = $ref->getNumberOfParameters();

        $args = [
            0,
            null,
            'anon_trade_lookup_test',
            $sku,
            1,
            null,
            'billing',
            null,
        ];

        if ($argc >= 9) {
            $args[] = null;
        }

        if ($argc >= 10) {
            $args[] = 'req_wechat_gateway_test';
        }

        $created = $orders->createOrder(...$args);

        $this->assertTrue((bool) ($created['ok'] ?? false), 'OrderManager::createOrder failed in test setup.');
        $orderNo = (string) ($created['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'external_trade_no' => $outTradeNo,
                'updated_at' => now(),
            ]);

        return $orderNo;
    }

    private function resolveSeededSku(): string
    {
        if (Schema::hasColumn('skus', 'org_id')) {
            $sku = (string) DB::table('skus')
                ->where('org_id', 0)
                ->where('is_active', true)
                ->orderBy('sku')
                ->value('sku');
            if ($sku !== '') {
                return $sku;
            }

            $sku = (string) DB::table('skus')
                ->where('org_id', 1)
                ->where('is_active', true)
                ->orderBy('sku')
                ->value('sku');
            if ($sku !== '') {
                return $sku;
            }
        }

        return (string) DB::table('skus')
            ->where('is_active', true)
            ->orderBy('sku')
            ->value('sku');
    }
}
