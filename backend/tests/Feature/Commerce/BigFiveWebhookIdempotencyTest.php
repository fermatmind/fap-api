<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class BigFiveWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedCommerce(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();
    }

    private function createBigFiveAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['domains_mean' => ['O' => 3.0, 'C' => 3.0, 'E' => 3.0, 'A' => 3.0, 'N' => 3.0]],
            'scores_pct' => ['O' => 50, 'C' => 50, 'E' => 50, 'A' => 50, 'N' => 50],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => [
                    'norms' => ['status' => 'CALIBRATED', 'group_id' => 'zh-CN_prod_all_18-60'],
                    'quality' => ['level' => 'A'],
                ],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createOrder(string $orderNo, string $sku, string $attemptId, string $anonId, int $amountCents): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => $amountCents,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => $amountCents,
            'amount_refunded' => 0,
            'item_sku' => $sku,
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);
    }

    public function test_big5_replayed_webhook_grants_once(): void
    {
        $this->seedCommerce();

        $anonId = 'anon_big5_webhook';
        $attemptId = $this->createBigFiveAttemptWithResult($anonId);
        $orderNo = 'ord_big5_dup_1';
        $this->createOrder($orderNo, 'SKU_BIG5_FULL_REPORT_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_big5_dup_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_big5_dup_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $first = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $first->assertStatus(200);
        $first->assertJson(['ok' => true]);

        $second = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_big5_dup_1')
            ->count());

        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->where('benefit_code', 'BIG5_FULL_REPORT')
            ->count());

        $this->assertSame('processed', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_big5_dup_1')
            ->value('status'));
    }

    public function test_big5_wrong_sku_fails_without_unlock(): void
    {
        $this->seedCommerce();

        $anonId = 'anon_big5_badsku';
        $attemptId = $this->createBigFiveAttemptWithResult($anonId);
        $orderNo = 'ord_big5_badsku_1';
        $this->createOrder($orderNo, 'SKU_BIG5_UNKNOWN', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_big5_badsku_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_big5_badsku_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $response = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'SKU_NOT_FOUND');

        $this->assertSame('failed', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_big5_badsku_1')
            ->value('status'));

        $this->assertSame('SKU_NOT_FOUND', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_big5_badsku_1')
            ->value('last_error_code'));

        $this->assertSame(0, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->count());
    }
}
