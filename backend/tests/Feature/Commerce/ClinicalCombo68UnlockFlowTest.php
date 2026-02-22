<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class ClinicalCombo68UnlockFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_paid_webhook_unlocks_clinical_combo_full_report(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_cc68_unlock';
        $attemptId = $this->createClinicalAttemptWithResult($anonId);
        $orderNo = 'ord_cc68_unlock_1';
        $this->createOrder($orderNo, 'SKU_CLINICAL_COMBO_68_PRO_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_cc68_unlock_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_cc68_unlock_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $resp = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $resp->assertStatus(200);
        $resp->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('order_no', $orderNo)
            ->where('benefit_code', 'CLINICAL_COMBO_68_PRO')
            ->count());

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, $anonId, 'public');
        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertFalse((bool) ($gate['locked'] ?? true));
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
            'scores_json' => [
                'depression' => ['raw' => 8, 't_score' => 46, 'level' => 'normal'],
                'anxiety' => ['raw' => 8, 't_score' => 50, 'level' => 'normal'],
                'stress' => ['raw' => 8, 't_score' => 47, 'level' => 'low'],
                'resilience' => ['raw' => 20, 't_score' => 43, 'level' => 'low'],
                'perfectionism' => ['raw' => 27, 't_score' => 20, 'level' => 'easygoing', 'sub_scores' => []],
                'ocd' => ['raw' => 0, 't_score' => 35, 'level' => 'subclinical'],
            ],
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
                    'scores' => [
                        'depression' => ['raw' => 8, 't_score' => 46, 'level' => 'normal', 'flags' => []],
                        'anxiety' => ['raw' => 8, 't_score' => 50, 'level' => 'normal'],
                        'stress' => ['raw' => 8, 't_score' => 47, 'level' => 'low'],
                        'resilience' => ['raw' => 20, 't_score' => 43, 'level' => 'low'],
                        'perfectionism' => ['raw' => 27, 't_score' => 20, 'level' => 'easygoing', 'sub_scores' => []],
                        'ocd' => ['raw' => 0, 't_score' => 35, 'level' => 'subclinical'],
                    ],
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

