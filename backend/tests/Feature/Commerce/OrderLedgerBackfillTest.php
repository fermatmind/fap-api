<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderLedgerBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_ledger_backfill_maps_legacy_status_and_existing_grants(): void
    {
        $orderNo = 'ord_backfill_contract_1';
        $attemptId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_backfill_contract',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'amount_total' => 299,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'fulfilled',
            'payment_state' => 'created',
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'contact_email_hash' => null,
            'external_user_ref' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
            'fulfilled_at' => now()->subHours(20),
            'refunded_at' => null,
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'status' => 'active',
            'benefit_ref' => 'anon_backfill_contract',
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $migration = require database_path('migrations/2026_03_26_120000_add_order_ledger_states_to_orders_table.php');
        $migration->up();

        $row = DB::table('orders')->where('order_no', $orderNo)->first();

        $this->assertNotNull($row);
        $this->assertSame('paid', (string) ($row->payment_state ?? ''));
        $this->assertSame('granted', (string) ($row->grant_state ?? ''));
        $this->assertSame('anon:anon_backfill_contract', (string) ($row->external_user_ref ?? ''));
    }

    public function test_order_ledger_backfill_marks_revoked_grants_without_active_entitlement(): void
    {
        $orderNo = 'ord_backfill_contract_revoked_1';
        $attemptId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_backfill_revoked',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'amount_total' => 299,
            'amount_refunded' => 299,
            'currency' => 'CNY',
            'status' => 'refunded',
            'payment_state' => 'created',
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'contact_email_hash' => null,
            'external_user_ref' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHours(20),
            'paid_at' => now()->subDay(),
            'fulfilled_at' => now()->subHours(22),
            'refunded_at' => now()->subHours(20),
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'status' => 'revoked',
            'benefit_ref' => 'anon_backfill_revoked',
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHours(20),
        ]);

        $migration = require database_path('migrations/2026_03_26_120000_add_order_ledger_states_to_orders_table.php');
        $migration->up();

        $row = DB::table('orders')->where('order_no', $orderNo)->first();

        $this->assertNotNull($row);
        $this->assertSame('refunded', (string) ($row->payment_state ?? ''));
        $this->assertSame('revoked', (string) ($row->grant_state ?? ''));
    }
}
