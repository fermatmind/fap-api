<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class Eq60WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_webhook_replay_is_idempotent_for_eq_unlock(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_eq_webhook';
        $attemptId = $this->createEqAttemptWithResult($anonId);
        $orderNo = 'ord_eq_webhook_1';
        $this->createOrder($orderNo, 'SKU_EQ_60_FULL_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_eq_webhook_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_eq_webhook_1',
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
            ->where('provider_event_id', 'evt_eq_webhook_1')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->where('benefit_code', 'EQ_60_FULL')
            ->count());
    }

    public function test_scale_mismatch_attempt_is_not_unlocked_for_eq_sku(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $attemptId = $this->createMbtiAttempt('anon_eq_order_mismatch');
        $orderNo = 'ord_eq_scale_mismatch_1';
        $this->createOrder($orderNo, 'SKU_EQ_60_FULL_299', $attemptId, 'anon_eq_order_mismatch', 299);

        $payload = [
            'provider_event_id' => 'evt_eq_scale_mismatch_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_eq_scale_mismatch_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $resp = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $resp->assertStatus(400);
        $resp->assertJsonPath('error_code', 'ATTEMPT_SCALE_MISMATCH');

        $this->assertSame(0, DB::table('benefit_grants')
            ->where('order_no', $orderNo)
            ->where('attempt_id', $attemptId)
            ->count());
        $this->assertSame('ATTEMPT_SCALE_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_eq_scale_mismatch_1')
            ->value('last_error_code'));
    }

    private function createEqAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(7),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = 'C';
        }

        $normed = $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            [
                'score_map' => (array) data_get($loader->loadOptions('v1'), 'score_map', []),
                'duration_ms' => 420000,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'content_manifest_hash' => $loader->resolveManifestHash('v1'),
            ]
        );

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($normed['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'normed_json' => $normed,
                'breakdown_json' => ['score_result' => $normed],
                'axis_scores_json' => ['score_result' => $normed],
            ],
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
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

