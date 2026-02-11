<?php

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class PaymentWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedCommerce(): void
    {
        (new Pr19CommerceSeeder())->run();
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

    public function test_duplicate_event_idempotent(): void
    {
        $this->seedCommerce();

        $orderNo = 'ord_dup_1';
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

        $payload = [
            'provider_event_id' => 'evt_dup_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_dup_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        $first = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(200);
        $first->assertJson(['ok' => true]);

        $second = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(1, DB::table('payment_events')->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
    }

    public function test_orphan_event_retries_after_order_created(): void
    {
        $this->seedCommerce();

        $orderNo = 'ord_orphan_1';
        $payload = [
            'provider_event_id' => 'evt_orphan_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_orphan_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ];

        $first = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(200);
        $first->assertJson([
            'ok' => false,
            'error_code' => 'ORDER_NOT_FOUND',
        ]);

        $this->assertSame('orphan', (string) DB::table('payment_events')
            ->where('provider_event_id', 'evt_orphan_1')
            ->value('status'));

        $attemptId = $this->createMbtiAttemptWithResult();

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

        $second = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson(['ok' => true]);

        $status = (string) DB::table('orders')->where('order_no', $orderNo)->value('status');
        $this->assertContains($status, ['paid', 'fulfilled']);
        $this->assertSame('processed', (string) DB::table('payment_events')
            ->where('provider_event_id', 'evt_orphan_1')
            ->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
    }

    public function test_invalid_signature_rejected_without_event_row(): void
    {
        $this->seedCommerce();

        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = [
            'provider_event_id' => 'evt_sig_1',
            'order_no' => 'ord_sig_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ];

        $res = $this->postJson('/api/v0.3/webhooks/payment/stripe', $payload);
        $res->assertStatus(400);

        $this->assertSame(0, DB::table('payment_events')->count());
    }
}
