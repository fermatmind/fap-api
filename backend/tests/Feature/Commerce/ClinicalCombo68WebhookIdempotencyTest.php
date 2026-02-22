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

final class ClinicalCombo68WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_webhook_replay_is_idempotent_for_clinical_unlock(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_cc68_webhook';
        $attemptId = $this->createClinicalAttemptWithResult($anonId);
        $orderNo = 'ord_cc68_webhook_1';
        $this->createOrder($orderNo, 'SKU_CLINICAL_COMBO_68_PRO_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_cc68_webhook_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_cc68_webhook_1',
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
            ->where('provider_event_id', 'evt_cc68_webhook_1')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->where('benefit_code', 'CLINICAL_COMBO_68_PRO')
            ->count());
    }

    public function test_owner_mismatch_attempt_is_not_unlocked(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $attemptId = $this->createClinicalAttemptWithResult('anon_attempt_owner');
        $orderNo = 'ord_cc68_owner_mismatch_1';
        $this->createOrder($orderNo, 'SKU_CLINICAL_COMBO_68_PRO_299', $attemptId, 'anon_order_owner', 299);

        $payload = [
            'provider_event_id' => 'evt_cc68_owner_mismatch_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_cc68_owner_mismatch_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $resp = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $resp->assertStatus(400);
        $resp->assertJsonPath('error_code', 'ATTEMPT_OWNER_MISMATCH');

        $this->assertSame(0, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->count());
    }

    private function createClinicalAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'CLINICAL_COMBO_68',
                'normed_json' => [
                    'scale_code' => 'CLINICAL_COMBO_68',
                    'quality' => [
                        'level' => 'A',
                        'crisis_alert' => false,
                        'crisis_reasons' => [],
                        'crisis_triggered_by' => [],
                        'inconsistency_flag' => false,
                        'completion_time_seconds' => 300,
                        'metrics' => ['neutral_rate' => 0.1, 'extreme_rate' => 0.2, 'longstring_max' => 4],
                        'flags' => [],
                    ],
                    'scores' => [],
                    'facts' => ['function_impairment_raw' => 0, 'function_impairment_level' => 'none'],
                    'report_tags' => [],
                ],
            ],
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
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

