<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\OrderManager;
use App\Services\Commerce\SkuCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SkuTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_only_returns_current_org_and_global_skus(): void
    {
        $this->seedSku('SKU_ORG_11_ONLY', 11, 1100, 'BENEFIT_ORG_11');
        $this->seedSku('SKU_ORG_22_ONLY', 22, 2200, 'BENEFIT_ORG_22');
        $this->seedSku('SKU_GLOBAL_ONLY', 0, 500, 'BENEFIT_GLOBAL');

        /** @var SkuCatalog $catalog */
        $catalog = app(SkuCatalog::class);

        $row = $catalog->getActiveSku('SKU_ORG_11_ONLY', 'MBTI', 11);
        $this->assertNotNull($row);
        $this->assertSame(11, (int) ($row->org_id ?? 0));
        $this->assertSame(1100, (int) ($row->price_cents ?? 0));

        $items = $catalog->listActiveSkus('MBTI', 11);
        $skus = collect($items)->pluck('sku')->all();

        $this->assertContains('SKU_ORG_11_ONLY', $skus);
        $this->assertContains('SKU_GLOBAL_ONLY', $skus);
        $this->assertNotContains('SKU_ORG_22_ONLY', $skus);
    }

    public function test_catalog_does_not_read_sku_from_other_org_without_global_fallback(): void
    {
        $this->seedSku('SKU_PRIVATE_ORG_22', 22, 2200, 'BENEFIT_ORG_22');

        /** @var SkuCatalog $catalog */
        $catalog = app(SkuCatalog::class);

        $row = $catalog->getActiveSku('SKU_PRIVATE_ORG_22', 'MBTI', 11);
        $this->assertNull($row);

        $meta = $catalog->resolveSkuMeta('SKU_PRIVATE_ORG_22', 'MBTI', 11);
        $this->assertSame('SKU_PRIVATE_ORG_22', (string) ($meta['requested_sku'] ?? ''));
        $this->assertNull($meta['effective_sku'] ?? null);
    }

    public function test_order_manager_uses_org_scoped_sku_during_order_creation(): void
    {
        $this->seedSku('SKU_ORDER_SCOPE_11', 11, 1111, 'BENEFIT_ORG_11');
        $this->seedSku('SKU_ORDER_SCOPE_22', 22, 2222, 'BENEFIT_ORG_22');

        /** @var OrderManager $orders */
        $orders = app(OrderManager::class);

        $notAllowed = $orders->createOrder(
            11,
            null,
            'anon_sku_tenant_forbidden_'.Str::random(8),
            'SKU_ORDER_SCOPE_22',
            1,
            null,
            'billing',
            null,
            'sku-tenant-forbidden@example.com'
        );

        $this->assertFalse((bool) ($notAllowed['ok'] ?? true));
        $this->assertSame('SKU_NOT_FOUND', (string) ($notAllowed['error'] ?? ''));

        $result = $orders->createOrder(
            11,
            null,
            'anon_sku_tenant_'.Str::random(8),
            'SKU_ORDER_SCOPE_11',
            2,
            null,
            'billing',
            null,
            'sku-tenant-isolation@example.com'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));

        $orderNo = (string) ($result['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        $this->assertSame(11, (int) ($order->org_id ?? 0));
        $this->assertSame('SKU_ORDER_SCOPE_11', (string) ($order->sku ?? ''));
        $this->assertSame(2222, (int) ($order->amount_cents ?? 0));
    }

    private function seedSku(string $sku, int $orgId, int $priceCents, string $benefitCode): void
    {
        $now = now();

        DB::table('skus')->updateOrInsert(
            [
                'org_id' => $orgId,
                'sku' => strtoupper($sku),
            ],
            [
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => strtoupper($benefitCode),
                'scope' => 'attempt',
                'price_cents' => $priceCents,
                'currency' => 'USD',
                'is_active' => true,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
