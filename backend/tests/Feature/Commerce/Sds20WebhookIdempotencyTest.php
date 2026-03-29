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
use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20WebhookIdempotencyTest extends TestCase
{
    use BuildsSds20ScorerInput;
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_webhook_replay_is_idempotent_for_sds_unlock(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $anonId = 'anon_sds_webhook';
        $attemptId = $this->createSdsAttemptWithResult($anonId);
        $orderNo = 'ord_sds_webhook_1';
        $this->createOrder($orderNo, 'SKU_SDS_20_FULL_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_sds_webhook_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_sds_webhook_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $first = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $first->assertStatus(200)->assertJsonPath('ok', true);

        $second = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $second->assertStatus(200)->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_sds_webhook_1')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->where('benefit_code', 'SDS_20_FULL')
            ->count());
    }

    public function test_scale_mismatch_attempt_is_not_unlocked(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->createMbtiAttempt('anon_sds_order_mismatch');
        $orderNo = 'ord_sds_scale_mismatch_1';
        $this->createOrder($orderNo, 'SKU_SDS_20_FULL_299', $attemptId, 'anon_sds_order_mismatch', 299);

        $payload = [
            'provider_event_id' => 'evt_sds_scale_mismatch_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_sds_scale_mismatch_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $resp = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $resp->assertStatus(200);
        $resp->assertJsonPath('error_code', 'ATTEMPT_SCALE_MISMATCH');
        $resp->assertJsonPath('rejected', true);
        $resp->assertJsonPath('reject_reason', 'ATTEMPT_SCALE_MISMATCH');

        $this->assertSame(0, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->count());

        $this->assertSame('ATTEMPT_SCALE_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_sds_scale_mismatch_1')
            ->value('last_error_code'));
        $this->assertSame('ATTEMPT_SCALE_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_sds_scale_mismatch_1')
            ->value('reason'));
    }

    private function createSdsAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'consent' => [
                        'accepted' => true,
                        'version' => 'SDS_20_v1_2026-02-22',
                        'locale' => 'zh-CN',
                    ],
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        $score = $this->scoreSds([], [
            'duration_ms' => 98000,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'SDS_20',
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createMbtiAttempt(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'MBTI',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'scoring_spec_version' => 'mbti_spec_v1',
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
}
