<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SecureOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_uses_server_price_for_normal_order(): void
    {
        $this->seedSku(1990);

        $result = app(PaymentService::class)->createOrder([
            'user_id' => 'u1',
            'item_sku' => 'MBTI_FULL',
            'currency' => 'USD',
            'amount_total' => 999999,
        ]);

        $this->assertTrue($result['ok']);

        $order = $result['order'];
        $orderId = $this->extractOrderId($order);

        $this->assertSame(1990, $this->extractAmountTotal($order));
        if ($this->hasAmountCents($order)) {
            $this->assertSame(1990, $this->extractAmountCents($order));
        }

        $dbOrder = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($dbOrder);
        $this->assertSame(1990, (int) ($dbOrder->amount_total ?? 0));
        if (isset($dbOrder->amount_cents)) {
            $this->assertSame(1990, (int) $dbOrder->amount_cents);
        }
    }

    public function test_create_order_ignores_client_supplied_amount_total_attack(): void
    {
        $this->seedSku(1990);

        $result = app(PaymentService::class)->createOrder([
            'user_id' => 'u1',
            'item_sku' => 'MBTI_FULL',
            'currency' => 'USD',
            'amount_total' => 1,
        ]);

        $this->assertTrue($result['ok']);

        $order = $result['order'];
        $orderId = $this->extractOrderId($order);

        $this->assertSame(1990, $this->extractAmountTotal($order));
        if ($this->hasAmountCents($order)) {
            $this->assertSame(1990, $this->extractAmountCents($order));
        }

        $dbOrder = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($dbOrder);
        $this->assertSame(1990, (int) ($dbOrder->amount_total ?? 0));
        if (isset($dbOrder->amount_cents)) {
            $this->assertSame(1990, (int) $dbOrder->amount_cents);
        }
    }

    private function seedSku(int $priceCents): void
    {
        $row = [
            'sku' => 'MBTI_FULL',
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'user',
            'price_cents' => $priceCents,
            'currency' => 'USD',
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

        DB::table('skus')->updateOrInsert(['sku' => 'MBTI_FULL'], $row);
    }

    private function extractOrderId(mixed $order): string
    {
        if (is_array($order)) {
            return (string) ($order['id'] ?? '');
        }

        return (string) ($order->id ?? '');
    }

    private function extractAmountTotal(mixed $order): int
    {
        if (is_array($order)) {
            return (int) ($order['amount_total'] ?? 0);
        }

        return (int) ($order->amount_total ?? 0);
    }

    private function hasAmountCents(mixed $order): bool
    {
        if (is_array($order)) {
            return array_key_exists('amount_cents', $order);
        }

        return is_object($order) && property_exists($order, 'amount_cents');
    }

    private function extractAmountCents(mixed $order): int
    {
        if (is_array($order)) {
            return (int) ($order['amount_cents'] ?? 0);
        }

        return (int) ($order->amount_cents ?? 0);
    }
}
