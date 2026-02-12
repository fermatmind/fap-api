<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentServiceOrgIdOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_ignores_client_org_id_and_uses_legacy_org_config(): void
    {
        config(['fap.legacy_org_id' => 7]);
        $this->seedSku();

        $result = app(PaymentService::class)->createOrder([
            'org_id' => 999,
            'user_id' => 'u_override',
            'item_sku' => 'MBTI_FULL',
            'quantity' => 1,
            'currency' => 'USD',
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));

        $order = $result['order'] ?? null;
        $orderId = is_array($order) ? (string) ($order['id'] ?? '') : (string) ($order->id ?? '');
        $this->assertNotSame('', $orderId);

        $stored = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($stored);
        $this->assertSame(7, (int) ($stored->org_id ?? 0));
        $this->assertNotSame(999, (int) ($stored->org_id ?? 0));
    }

    private function seedSku(): void
    {
        $row = [
            'sku' => 'MBTI_FULL',
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'user',
            'price_cents' => 1990,
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
}
