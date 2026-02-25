<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillOrdersScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_orders(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'ops_backfill_orders_known',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 1,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'client_version' => null,
            'channel' => 'test',
            'referrer' => null,
            'started_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        $orderNo = 'ord_ops_backfill_known_' . Str::lower(Str::random(8));
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'ops_backfill_orders_known',
            'device_id' => null,
            'sku' => 'SKU_TEST',
            'item_sku' => 'SKU_TEST',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'scale_code_v2' => null,
            'scale_uid' => null,
            'amount_cents' => 0,
            'amount_total' => 0,
            'amount_refunded' => 0,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stub',
            'provider_order_id' => null,
            'external_trade_no' => null,
            'request_id' => null,
            'created_ip' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-orders-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_orders_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_orders_without_attempt_context(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $orderId = (string) Str::uuid();
        $orderNo = 'ord_ops_backfill_unknown_' . Str::lower(Str::random(8));
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'ops_backfill_orders_unknown',
            'device_id' => null,
            'sku' => 'SKU_TEST',
            'item_sku' => 'SKU_TEST',
            'quantity' => 1,
            'target_attempt_id' => (string) Str::uuid(),
            'scale_code_v2' => null,
            'scale_uid' => null,
            'amount_cents' => 0,
            'amount_total' => 0,
            'amount_refunded' => 0,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stub',
            'provider_order_id' => null,
            'external_trade_no' => null,
            'request_id' => null,
            'created_ip' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-orders-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_orders_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
