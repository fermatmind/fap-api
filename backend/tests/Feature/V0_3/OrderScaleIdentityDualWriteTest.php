<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\Commerce\OrderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_manager_dual_mode_writes_order_scale_identity_columns(): void
    {
        config()->set('scale_identity.write_mode', 'dual');

        $attemptId = $this->createAttempt('anon_order_dual');
        $this->seedSku('SKU_SDS_20_FULL_299', 'SDS_20');

        $result = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_order_dual',
            'SKU_SDS_20_FULL_299',
            1,
            $attemptId,
            'billing',
            null,
            'anon_order_dual@example.com'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $orderNo = (string) ($result['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        $row = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($row);
        $this->assertSame('DEPRESSION_SCREENING_STANDARD', (string) ($row->scale_code_v2 ?? ''));
        $this->assertSame('44444444-4444-4444-8444-444444444444', (string) ($row->scale_uid ?? ''));
    }

    public function test_order_manager_legacy_mode_keeps_order_identity_columns_nullable(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');

        $attemptId = $this->createAttempt('anon_order_legacy');
        $this->seedSku('SKU_SDS_20_FULL_299', 'SDS_20');

        $result = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_order_legacy',
            'SKU_SDS_20_FULL_299',
            1,
            $attemptId,
            'billing',
            null,
            'anon_order_legacy@example.com'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $orderNo = (string) ($result['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        $row = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->scale_code_v2);
        $this->assertNull($row->scale_uid);
    }

    private function createAttempt(string $anonId): string
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        return (string) $attempt->id;
    }

    private function seedSku(string $sku, string $scaleCode): void
    {
        $now = now();
        DB::table('skus')->updateOrInsert(
            ['sku' => $sku],
            [
                'scale_code' => $scaleCode,
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'SDS_20_FULL',
                'scope' => 'attempt',
                'price_cents' => 299,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
