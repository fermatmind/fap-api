<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_commerce_reconcile_reports_mismatch_and_writes_snapshot(): void
    {
        $today = now()->setTimezone('Asia/Shanghai')->format('Y-m-d');
        $paidOrderNo = 'ord_recon_paid_1';
        $missingGrantOrderNo = 'ord_recon_missing_1';
        $orphanGrantOrderNo = 'ord_recon_orphan_1';

        DB::table('orders')->insert([
            [
                'id' => (string) Str::uuid(),
                'order_no' => $paidOrderNo,
                'org_id' => 0,
                'user_id' => null,
                'anon_id' => 'anon_recon_1',
                'sku' => 'SKU_BIG5_FULL_REPORT_299',
                'item_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'requested_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'effective_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'entitlement_id' => null,
                'quantity' => 1,
                'target_attempt_id' => (string) Str::uuid(),
                'amount_cents' => 29900,
                'amount_total' => 29900,
                'amount_refunded' => 0,
                'currency' => 'CNY',
                'status' => 'paid',
                'provider' => 'billing',
                'external_trade_no' => null,
                'provider_order_id' => null,
                'idempotency_key' => null,
                'request_id' => null,
                'device_id' => null,
                'created_ip' => null,
                'paid_at' => now()->subMinutes(3),
                'fulfilled_at' => null,
                'refunded_at' => null,
                'refund_amount_cents' => null,
                'refund_reason' => null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(4),
                'updated_at' => now()->subMinutes(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'order_no' => $missingGrantOrderNo,
                'org_id' => 0,
                'user_id' => null,
                'anon_id' => 'anon_recon_2',
                'sku' => 'SKU_BIG5_FULL_REPORT_299',
                'item_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'requested_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'effective_sku' => 'SKU_BIG5_FULL_REPORT_299',
                'entitlement_id' => null,
                'quantity' => 1,
                'target_attempt_id' => (string) Str::uuid(),
                'amount_cents' => 29900,
                'amount_total' => 29900,
                'amount_refunded' => 0,
                'currency' => 'CNY',
                'status' => 'paid',
                'provider' => 'billing',
                'external_trade_no' => null,
                'provider_order_id' => null,
                'idempotency_key' => null,
                'request_id' => null,
                'device_id' => null,
                'created_ip' => null,
                'paid_at' => now()->subMinutes(2),
                'fulfilled_at' => null,
                'refunded_at' => null,
                'refund_amount_cents' => null,
                'refund_reason' => null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(1),
            ],
        ]);

        DB::table('benefit_grants')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'user_id' => null,
                'benefit_code' => 'BIG5_FULL',
                'scope' => 'attempt',
                'attempt_id' => (string) Str::uuid(),
                'order_no' => $paidOrderNo,
                'status' => 'active',
                'benefit_ref' => 'grant_ref_1',
                'benefit_type' => 'report_unlock',
                'source_order_id' => (string) Str::uuid(),
                'source_event_id' => null,
                'expires_at' => null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(1),
                'updated_at' => now()->subMinutes(1),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'user_id' => null,
                'benefit_code' => 'BIG5_FULL',
                'scope' => 'attempt',
                'attempt_id' => (string) Str::uuid(),
                'order_no' => $orphanGrantOrderNo,
                'status' => 'active',
                'benefit_ref' => 'grant_ref_2',
                'benefit_type' => 'report_unlock',
                'source_order_id' => (string) Str::uuid(),
                'source_event_id' => null,
                'expires_at' => null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(1),
                'updated_at' => now()->subMinutes(1),
            ],
        ]);

        $exitCode = Artisan::call('commerce:reconcile', [
            '--date' => $today,
            '--org_id' => 0,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);
        $line = trim((string) Artisan::output());
        $this->assertNotSame('', $line);

        $payload = json_decode($line, true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame($today, (string) ($payload['date'] ?? ''));
        $this->assertSame(2, (int) ($payload['paid_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['unlocked_count'] ?? 0));
        $this->assertSame(2, (int) ($payload['mismatch_count'] ?? 0));

        $mismatchMap = [];
        foreach ((array) ($payload['mismatches'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mismatchMap[(string) ($row['order_no'] ?? '')] = (string) ($row['reason'] ?? '');
        }
        $this->assertSame('PAID_WITHOUT_ACTIVE_GRANT', $mismatchMap[$missingGrantOrderNo] ?? '');
        $this->assertSame('ACTIVE_GRANT_WITHOUT_PAID_ORDER', $mismatchMap[$orphanGrantOrderNo] ?? '');

        $snapshot = DB::table('payment_reconcile_snapshots')
            ->where('org_id', 0)
            ->where('snapshot_date', $today)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(2, (int) ($snapshot->paid_orders_count ?? -1));
        $this->assertSame(1, (int) ($snapshot->paid_without_benefit_count ?? -1));
        $this->assertSame(1, (int) ($snapshot->benefit_without_report_count ?? -1));
    }
}
