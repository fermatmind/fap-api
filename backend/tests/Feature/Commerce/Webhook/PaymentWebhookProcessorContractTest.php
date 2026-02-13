<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce\Webhook;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\PaymentWebhookProcessor;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class PaymentWebhookProcessorContractTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_duplicate_event_is_idempotent_and_db_state_is_stable(): void
    {
        (new Pr19CommerceSeeder())->run();

        $attemptId = $this->createMbtiAttemptWithResult();
        $orderNo = 'ord_contract_dup_1';

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_test',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 199,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_REPORT_FULL',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $payload = [
            'provider_event_id' => 'evt_contract_dup_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_contract_dup_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
            'event_type' => 'payment_succeeded',
        ];

        $first = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $first->assertStatus(200)->assertJson(['ok' => true]);

        $second = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $second->assertStatus(200)->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_contract_dup_1')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
        $this->assertContains(
            (string) DB::table('orders')->where('order_no', $orderNo)->value('status'),
            ['paid', 'fulfilled']
        );
    }

    public function test_provider_mismatch_is_rejected_without_entitlement_write(): void
    {
        (new Pr19CommerceSeeder())->run();

        $orderNo = 'ord_contract_provider_mismatch_1';

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stripe',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $res = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_provider_mismatch_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ]);

        $res->assertStatus(400)->assertJson([
            'ok' => false,
            'error_code' => 'PROVIDER_MISMATCH',
        ]);

        $this->assertSame('created', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame('rejected_provider_mismatch', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_contract_provider_mismatch_1')
            ->value('last_error_code'));
        $this->assertSame(0, DB::table('benefit_grants')->count());
    }

    public function test_amount_and_currency_mismatch_are_rejected_with_stable_contract(): void
    {
        (new Pr19CommerceSeeder())->run();
        $processor = app(PaymentWebhookProcessor::class);

        $orderNo = 'ord_contract_amount_currency_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $amountMismatch = $processor->handle('billing', [
            'provider_event_id' => 'evt_contract_amount_mismatch_1',
            'order_no' => $orderNo,
            'amount_cents' => 1,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ], 0, null, null, true);

        $this->assertFalse((bool) ($amountMismatch['ok'] ?? true));
        $this->assertSame(404, (int) ($amountMismatch['status'] ?? 0));
        $this->assertSame('AMOUNT_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_contract_amount_mismatch_1')
            ->value('last_error_code'));

        $currencyMismatch = $processor->handle('billing', [
            'provider_event_id' => 'evt_contract_currency_mismatch_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'CNY',
            'event_type' => 'payment_succeeded',
        ], 0, null, null, true);

        $this->assertFalse((bool) ($currencyMismatch['ok'] ?? true));
        $this->assertSame(404, (int) ($currencyMismatch['status'] ?? 0));
        $this->assertSame('CURRENCY_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_contract_currency_mismatch_1')
            ->value('last_error_code'));
    }

    public function test_missing_order_keeps_contract_and_does_not_grant_benefit(): void
    {
        (new Pr19CommerceSeeder())->run();

        $res = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_order_missing_1',
            'order_no' => 'ord_missing_contract',
            'amount_cents' => 199,
            'currency' => 'CNY',
            'event_type' => 'payment_succeeded',
        ], ['X-Org-Id' => '0']);

        $res->assertStatus(404)->assertJson([
            'ok' => false,
            'error_code' => 'ORDER_NOT_FOUND',
        ]);

        $this->assertSame(0, DB::table('benefit_grants')->count());
        $this->assertSame(0, DB::table('orders')->where('order_no', 'ord_missing_contract')->count());
    }

    public function test_refund_event_does_not_issue_duplicate_benefit_side_effects(): void
    {
        (new Pr19CommerceSeeder())->run();

        $orderNo = 'ord_contract_refund_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $paid = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_refund_paid_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_contract_refund_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ], ['X-Org-Id' => '0']);

        $paid->assertStatus(200)->assertJson(['ok' => true]);

        $refund = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_refund_1',
            'order_no' => $orderNo,
            'event_type' => 'refund_succeeded',
            'refund_amount_cents' => 4990,
            'refund_reason' => 'requested_by_customer',
        ], ['X-Org-Id' => '0']);

        $refund->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertSame('refunded', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
    }

    public function test_lock_timeout_returns_webhook_busy_contract(): void
    {
        (new Pr19CommerceSeeder())->run();

        Cache::shouldReceive('lock')
            ->once()
            ->andReturn(new class {
                public function block(int $seconds, callable $callback): mixed
                {
                    throw new LockTimeoutException('busy');
                }
            });

        $processor = app(PaymentWebhookProcessor::class);
        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_contract_busy_1',
            'order_no' => 'ord_contract_busy_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame(500, (int) ($result['status'] ?? 0));
        $this->assertSame('WEBHOOK_BUSY', (string) ($result['error_code'] ?? ''));
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_oversized_payload_is_rejected_by_controller_with_413(): void
    {
        (new Pr19CommerceSeeder())->run();
        config(['payments.webhook_max_payload_bytes' => 1024]);

        $res = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_payload_too_large_1',
            'order_no' => 'ord_contract_payload_too_large_1',
            'blob' => str_repeat('X', 2048),
        ]);

        $res->assertStatus(413)->assertJson([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
        ]);
        $this->assertSame(0, DB::table('payment_events')->count());
    }

    public function test_controller_propagates_processor_status_in_contract_mode(): void
    {
        (new Pr19CommerceSeeder())->run();

        $mock = Mockery::mock(PaymentWebhookProcessor::class);
        $mock->shouldReceive('process')
            ->once()
            ->andReturn([
                'ok' => false,
                'error_code' => 'X',
                'status' => 404,
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $mock);

        $res = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_contract_status_propagation_1',
            'order_no' => 'ord_contract_status_propagation_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ]);

        $res->assertStatus(404);
    }

    private function createMbtiAttemptWithResult(): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.1-TEST');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_test',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
