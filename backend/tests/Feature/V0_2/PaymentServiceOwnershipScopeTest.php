<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentServiceOwnershipScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_returns_not_found_for_non_owner_actor(): void
    {
        $this->seedSku(1990);

        $order = app(PaymentService::class)->createOrder([
            'user_id' => 'owner_user_1',
            'item_sku' => 'MBTI_FULL',
            'currency' => 'USD',
        ]);

        $orderId = (string) (($order['order']->id ?? '') ?: ($order['order']['id'] ?? ''));
        $this->assertNotSame('', $orderId);

        $result = app(PaymentService::class)->markPaid($orderId, 'other_user_2', null);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame(404, (int) ($result['status'] ?? 0));
        $this->assertSame('ORDER_NOT_FOUND', (string) ($result['error_code'] ?? ''));
    }

    public function test_fulfill_returns_not_found_for_non_owner_actor(): void
    {
        $this->seedSku(1990);

        $order = app(PaymentService::class)->createOrder([
            'user_id' => 'owner_user_1',
            'item_sku' => 'MBTI_FULL',
            'currency' => 'USD',
        ]);

        $orderId = (string) (($order['order']->id ?? '') ?: ($order['order']['id'] ?? ''));
        $this->assertNotSame('', $orderId);

        $result = app(PaymentService::class)->fulfill($orderId, 'other_user_2', null);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame(404, (int) ($result['status'] ?? 0));
        $this->assertSame('ORDER_NOT_FOUND', (string) ($result['error_code'] ?? ''));
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
}
