<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillPaymentEventsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_payment_events(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'ops_backfill_payments_known',
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

        $orderNo = 'ord_ops_backfill_payments_known_' . Str::lower(Str::random(8));
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'ops_backfill_payments_known',
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

        $eventId = (string) Str::uuid();
        DB::table('payment_events')->insert([
            'id' => $eventId,
            'org_id' => 0,
            'provider' => 'stub',
            'provider_event_id' => 'evt_backfill_' . Str::lower(Str::random(10)),
            'order_id' => (string) Str::uuid(),
            'event_type' => 'payment_succeeded',
            'order_no' => $orderNo,
            'payload_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'signature_ok' => 1,
            'status' => 'received',
            'attempts' => 0,
            'last_error_code' => null,
            'last_error_message' => null,
            'processed_at' => null,
            'handled_at' => null,
            'handle_status' => null,
            'reason' => null,
            'requested_sku' => null,
            'effective_sku' => null,
            'entitlement_id' => null,
            'scale_code_v2' => null,
            'scale_uid' => null,
            'payload_size_bytes' => 0,
            'payload_sha256' => null,
            'payload_s3_key' => null,
            'payload_excerpt' => null,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-payment-events-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_payment_events_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('payment_events')->where('id', $eventId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_payment_events_without_order_context(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $eventId = (string) Str::uuid();
        DB::table('payment_events')->insert([
            'id' => $eventId,
            'org_id' => 0,
            'provider' => 'stub',
            'provider_event_id' => 'evt_backfill_unknown_' . Str::lower(Str::random(10)),
            'order_id' => (string) Str::uuid(),
            'event_type' => 'payment_succeeded',
            'order_no' => 'missing_order_no_' . Str::lower(Str::random(8)),
            'payload_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'signature_ok' => 1,
            'status' => 'received',
            'attempts' => 0,
            'last_error_code' => null,
            'last_error_message' => null,
            'processed_at' => null,
            'handled_at' => null,
            'handle_status' => null,
            'reason' => null,
            'requested_sku' => null,
            'effective_sku' => null,
            'entitlement_id' => null,
            'scale_code_v2' => null,
            'scale_uid' => null,
            'payload_size_bytes' => 0,
            'payload_sha256' => null,
            'payload_s3_key' => null,
            'payload_excerpt' => null,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-payment-events-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_payment_events_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('payment_events')->where('id', $eventId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
