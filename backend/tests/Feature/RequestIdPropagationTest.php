<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\OrderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class RequestIdPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_responses_echo_request_id_in_header_and_body_for_key_routes(): void
    {
        config([
            'payments.providers.stripe.enabled' => true,
            'services.stripe.webhook_secret' => 'whsec_req_id_probe',
            'fap.rate_limits.bypass_in_test_env' => true,
        ]);

        $requestId = 'req_fer2_42_errors';

        $webhook = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/v0.3/webhooks/payment/stripe', $this->stripePayload('evt_reqid_err_1'));
        $webhook->assertStatus(400);
        $this->assertSame($requestId, (string) $webhook->headers->get('X-Request-Id'));
        $this->assertSame($requestId, (string) $webhook->json('request_id'));

        $attemptStart = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/v0.3/attempts/start', []);
        $this->assertGreaterThanOrEqual(400, $attemptStart->getStatusCode());
        $this->assertSame($requestId, (string) $attemptStart->headers->get('X-Request-Id'));
        $this->assertSame($requestId, (string) $attemptStart->json('request_id'));

        $orderLookup = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/v0.3/orders/lookup', []);
        $this->assertGreaterThanOrEqual(400, $orderLookup->getStatusCode());
        $this->assertSame($requestId, (string) $orderLookup->headers->get('X-Request-Id'));
        $this->assertSame($requestId, (string) $orderLookup->json('request_id'));

        $report = $this->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/v0.3/attempts/not-a-uuid/report');
        $this->assertGreaterThanOrEqual(400, $report->getStatusCode());
        $this->assertSame($requestId, (string) $report->headers->get('X-Request-Id'));
        $this->assertSame($requestId, (string) $report->json('request_id'));
    }

    public function test_middleware_writes_request_id_into_log_context(): void
    {
        Log::spy();

        $requestId = 'req_fer2_42_log_context';
        $response = $this->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/healthz');

        $response->assertStatus(200);
        $this->assertSame($requestId, (string) $response->headers->get('X-Request-Id'));

        Log::shouldHaveReceived('withContext')
            ->with(Mockery::on(static function ($context) use ($requestId): bool {
                return is_array($context)
                    && (string) ($context['request_id'] ?? '') === $requestId;
            }))
            ->atLeast()
            ->once();

        Log::shouldHaveReceived('withoutContext')
            ->atLeast()
            ->once();
    }

    public function test_event_recorder_uses_request_attribute_request_id_when_header_absent(): void
    {
        $requestId = 'req_fer2_42_event_recorder';

        $request = Request::create('/api/v0.3/events', 'POST', [
            'anon_id' => 'anon_reqid_event_recorder',
        ]);
        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('org_id', 0);

        app(EventRecorder::class)->recordFromRequest($request, 'request_id_probe', null, [
            'probe' => true,
        ]);

        $event = DB::table('events')
            ->where('event_code', 'request_id_probe')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($requestId, (string) ($event->request_id ?? ''));
    }

    public function test_order_manager_writes_request_id_from_http_request_context(): void
    {
        $this->seedSku('SKU_REQ_ID_PROBE', 'MBTI');

        $requestId = 'req_fer2_42_order_create';
        $result = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_reqid_order',
            'SKU_REQ_ID_PROBE',
            1,
            null,
            'billing',
            null,
            'anon_reqid_order@example.com',
            $requestId
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $orderNo = (string) ($result['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        $this->assertSame($requestId, (string) ($order->request_id ?? ''));
    }

    public function test_payment_webhook_persists_request_id_into_payment_events(): void
    {
        config([
            'payments.providers.stripe.enabled' => true,
            'services.stripe.webhook_secret' => 'whsec_req_id_storage',
            'fap.rate_limits.bypass_in_test_env' => true,
        ]);

        $requestId = 'req_fer2_42_webhook_store';
        $providerEventId = 'evt_reqid_store_'.Str::lower(Str::random(10));

        $response = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/v0.3/webhooks/payment/stripe', $this->stripePayload($providerEventId));

        $response->assertStatus(400);
        $this->assertSame($requestId, (string) $response->headers->get('X-Request-Id'));
        $this->assertSame($requestId, (string) $response->json('request_id'));

        $event = DB::table('payment_events')
            ->where('provider', 'stripe')
            ->where('provider_event_id', $providerEventId)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($requestId, (string) ($event->request_id ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function stripePayload(string $providerEventId): array
    {
        return [
            'id' => $providerEventId,
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'id' => 'ch_'.Str::lower(Str::random(8)),
                    'amount' => 199,
                    'currency' => 'usd',
                    'metadata' => [
                        'order_no' => 'ord_reqid_'.Str::lower(Str::random(8)),
                    ],
                ],
            ],
        ];
    }

    private function seedSku(string $sku, string $scaleCode): void
    {
        $now = now();

        DB::table('skus')->updateOrInsert(
            ['org_id' => 0, 'sku' => $sku],
            [
                'scale_code' => $scaleCode,
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 199,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
