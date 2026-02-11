<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Exceptions\InvalidSkuException;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OrderPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_uses_server_side_sku_price_when_amount_total_is_tampered(): void
    {
        $this->seedSku('MBTI_FULL', 1990, 'CNY');

        $result = app(PaymentService::class)->createOrder([
            'user_id' => 'u_pricing_1',
            'item_sku' => 'MBTI_FULL',
            'quantity' => 1,
            'currency' => 'CNY',
            'amount_total' => 1,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));

        $order = $result['order'] ?? null;
        $orderId = is_array($order) ? (string) ($order['id'] ?? '') : (string) ($order->id ?? '');
        $this->assertNotSame('', $orderId);

        $stored = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($stored);
        $this->assertSame(1990, (int) ($stored->amount_total ?? 0));
        $this->assertSame(1990, (int) ($stored->amount_cents ?? 0));
    }

    public function test_create_order_throws_invalid_sku_exception_when_sku_does_not_exist(): void
    {
        try {
            app(PaymentService::class)->createOrder([
                'user_id' => 'u_pricing_2',
                'item_sku' => 'SKU_NOT_EXIST',
                'quantity' => 1,
                'currency' => 'CNY',
                'amount_total' => 1,
            ]);
            $this->fail('Expected InvalidSkuException was not thrown.');
        } catch (InvalidSkuException $e) {
            $this->assertSame('INVALID_SKU', $e->errorCode());
            $this->assertStringContainsString('SKU_NOT_EXIST', $e->getMessage());
            $this->assertStringContainsString('CNY', $e->getMessage());
        }
    }

    private function seedSku(string $sku, int $priceCents, string $currency): void
    {
        $row = [
            'sku' => $sku,
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'user',
            'price_cents' => $priceCents,
            'currency' => strtoupper($currency),
            'is_active' => 1,
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('skus', 'org_id')) {
            $row['org_id'] = 1;
        }
        if (Schema::hasColumn('skus', 'title')) {
            $row['title'] = 'MBTI Full';
        }

        DB::table('skus')->updateOrInsert(['sku' => $sku], $row);
    }
}
