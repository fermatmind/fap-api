<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentsMockWebhookProviderIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_webhook_does_not_pollute_billing_row_when_provider_event_id_is_shared(): void
    {
        $orderId = (string) Str::uuid();
        $providerOrderId = 'po_evt_shared';
        $now = now();

        DB::table('orders')->insert([
            'id' => $orderId,
            'user_id' => null,
            'anon_id' => null,
            'device_id' => null,
            'provider' => 'mock',
            'provider_order_id' => $providerOrderId,
            'status' => 'pending',
            'currency' => 'USD',
            'amount_total' => 1000,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_REPORT_FULL',
            'request_id' => null,
            'created_ip' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'order_no' => 'ord_evt_shared_provider_isolation',
            'org_id' => 0,
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 1000,
            'external_trade_no' => null,
        ]);

        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'provider' => 'billing',
            'provider_event_id' => 'evt_shared',
            'order_id' => $orderId,
            'event_type' => 'billing.seed',
            'payload_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signature_ok' => true,
            'handled_at' => $now->copy()->subHour(),
            'handle_status' => 'billing_seed_hold',
            'request_id' => null,
            'ip' => null,
            'headers_digest' => null,
            'status' => 'received',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $billingBefore = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_shared')
            ->first();

        $result = app(PaymentService::class)->handleWebhookMock(
            [
                'provider_event_id' => 'evt_shared',
                'provider_order_id' => $providerOrderId,
                'order_id' => $orderId,
                'event_type' => 'mock.payment_succeeded',
                'type' => 'mock.payment_succeeded',
                'amount_total' => 1000,
                'currency' => 'USD',
            ],
            [
                'signature_ok' => true,
            ]
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse((bool) ($result['idempotent'] ?? true));

        $billingAfter = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_shared')
            ->first();

        $mockRow = DB::table('payment_events')
            ->where('provider', 'mock')
            ->where('provider_event_id', 'evt_shared')
            ->first();

        $this->assertNotNull($billingBefore);
        $this->assertNotNull($billingAfter);
        $this->assertNotNull($mockRow);

        $this->assertSame('billing.seed', (string) ($billingAfter->event_type ?? ''));
        $this->assertSame('received', (string) ($billingAfter->status ?? ''));
        $this->assertSame((string) ($billingBefore->handle_status ?? ''), (string) ($billingAfter->handle_status ?? ''));
        $this->assertSame((string) ($billingBefore->handled_at ?? ''), (string) ($billingAfter->handled_at ?? ''));

        $this->assertSame(2, DB::table('payment_events')
            ->where('provider_event_id', 'evt_shared')
            ->count());

        $this->assertSame('mock.payment_succeeded', (string) ($mockRow->event_type ?? ''));
    }
}
