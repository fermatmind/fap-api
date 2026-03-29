<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

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

final class PaymentEventScaleIdentityDualWriteTest extends TestCase
{
    use BuildsSds20ScorerInput;
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_webhook_dual_mode_writes_payment_event_scale_identity_columns(): void
    {
        config()->set('scale_identity.write_mode', 'dual');
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $anonId = 'anon_payevt_dual';
        $attemptId = $this->createSdsAttemptWithResult($anonId);
        $orderNo = 'ord_payevt_dual_1';
        $this->createOrder($orderNo, 'SKU_SDS_20_FULL_299', $attemptId, $anonId, 299);

        $resp = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_payevt_dual_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_payevt_dual_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ], ['X-Org-Id' => '0']);

        $resp->assertStatus(200);
        $resp->assertJsonPath('ok', true);

        $event = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_payevt_dual_1')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('DEPRESSION_SCREENING_STANDARD', (string) ($event->scale_code_v2 ?? ''));
        $this->assertSame('44444444-4444-4444-8444-444444444444', (string) ($event->scale_uid ?? ''));
    }

    public function test_webhook_legacy_mode_keeps_payment_event_scale_identity_columns_nullable(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $anonId = 'anon_payevt_legacy';
        $attemptId = $this->createSdsAttemptWithResult($anonId);
        $orderNo = 'ord_payevt_legacy_1';
        $this->createOrder($orderNo, 'SKU_SDS_20_FULL_299', $attemptId, $anonId, 299);

        $resp = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_payevt_legacy_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_payevt_legacy_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ], ['X-Org-Id' => '0']);

        $resp->assertStatus(200);
        $resp->assertJsonPath('ok', true);

        $event = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_payevt_legacy_1')
            ->first();

        $this->assertNotNull($event);
        $this->assertNull($event->scale_code_v2);
        $this->assertNull($event->scale_uid);
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
