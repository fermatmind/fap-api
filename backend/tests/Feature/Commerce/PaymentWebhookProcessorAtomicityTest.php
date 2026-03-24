<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\GenerateReportPdfJob;
use App\Jobs\GenerateReportSnapshotJob;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class PaymentWebhookProcessorAtomicityTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_atomic_rollback_then_retry_can_process_same_event(): void
    {
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_atomic_1';
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
            'provider_event_id' => 'evt_atomic_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_atomic_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        $walletService = Mockery::mock(BenefitWalletService::class)->makePartial();
        $callCount = 0;
        $walletService
            ->shouldReceive('topUp')
            ->twice()
            ->andReturnUsing(function (
                int $orgId,
                string $benefitCode,
                int $delta,
                string $idempotencyKey,
                array $meta = []
            ) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('simulated_atomicity_failure');
                }

                $real = new BenefitWalletService;

                return $real->topUp($orgId, $benefitCode, $delta, $idempotencyKey, $meta);
            });
        $this->app->instance(BenefitWalletService::class, $walletService);

        $first = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(500);
        $first->assertJson([
            'ok' => false,
            'error_code' => 'WALLET_TOPUP_EXCEPTION',
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_atomic_1')
            ->count());
        $this->assertSame(
            'post_commit_failed',
            (string) (DB::table('payment_events')
                ->where('provider', 'billing')
                ->where('provider_event_id', 'evt_atomic_1')
                ->value('status') ?? '')
        );
        $this->assertSame('fulfilled', (string) (DB::table('orders')
            ->where('order_no', $orderNo)
            ->value('status') ?? ''));

        $second = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'order_no' => $orderNo,
            'provider_event_id' => 'evt_atomic_1',
        ]);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_atomic_1')
            ->count());
        $this->assertSame(
            'processed',
            (string) (DB::table('payment_events')
                ->where('provider', 'billing')
                ->where('provider_event_id', 'evt_atomic_1')
                ->value('status') ?? '')
        );
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
    }

    public function test_settled_credit_pack_new_event_id_does_not_retopup_wallet(): void
    {
        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_atomic_credit_dup';
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

        $first = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_atomic_credit_dup_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_atomic_credit_dup_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ], [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(200);
        $first->assertJson([
            'ok' => true,
            'order_no' => $orderNo,
            'provider_event_id' => 'evt_atomic_credit_dup_1',
        ]);

        $second = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_atomic_credit_dup_2',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_atomic_credit_dup_2',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ], [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'duplicate' => true,
            'order_no' => $orderNo,
            'provider_event_id' => 'evt_atomic_credit_dup_2',
        ]);

        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
        $this->assertSame(2, DB::table('payment_events')->where('provider', 'billing')->whereIn('provider_event_id', ['evt_atomic_credit_dup_1', 'evt_atomic_credit_dup_2'])->count());
    }

    public function test_report_unlock_split_replay_converges_without_duplicate_side_effects(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_atomic_report',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => 'mbti_spec_v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderNo = 'ord_atomic_report_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_atomic_report',
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
            'provider_event_id' => 'evt_atomic_report_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_atomic_report_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ];

        $realSnapshotStore = app(ReportSnapshotStore::class);
        $snapshotStore = Mockery::mock(ReportSnapshotStore::class)->makePartial();
        $seedCalls = 0;
        $snapshotStore->shouldReceive('seedPendingSnapshot')
            ->twice()
            ->andReturnUsing(function (int $orgId, string $attemptIdArg, ?string $orderNoArg, array $meta) use (&$seedCalls, $realSnapshotStore): void {
                $seedCalls++;
                if ($seedCalls === 1) {
                    throw new \RuntimeException('simulated_seed_snapshot_failure');
                }

                $realSnapshotStore->seedPendingSnapshot($orgId, $attemptIdArg, $orderNoArg, $meta);
            });
        $this->app->instance(ReportSnapshotStore::class, $snapshotStore);

        Queue::fake();

        $first = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertStatus(500);
        $first->assertJson([
            'ok' => false,
            'error_code' => 'SEED_SNAPSHOT_FAILED',
        ]);

        $this->assertSame(
            'post_commit_failed',
            (string) (DB::table('payment_events')
                ->where('provider', 'billing')
                ->where('provider_event_id', 'evt_atomic_report_1')
                ->value('status') ?? '')
        );
        $this->assertSame('fulfilled', (string) (DB::table('orders')
            ->where('order_no', $orderNo)
            ->value('status') ?? ''));
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
        $this->assertSame(0, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
        Queue::assertNothingPushed();

        $second = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'order_no' => $orderNo,
            'provider_event_id' => 'evt_atomic_report_1',
        ]);
        $this->assertFalse((bool) ($second->json('duplicate') ?? false));
        $this->assertSame(
            'processed',
            (string) (DB::table('payment_events')
                ->where('provider', 'billing')
                ->where('provider_event_id', 'evt_atomic_report_1')
                ->value('status') ?? '')
        );
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
        $this->assertSame(1, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
        Queue::assertPushed(GenerateReportPdfJob::class, 1);

        $third = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $third->assertStatus(200);
        $third->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(
            'processed',
            (string) (DB::table('payment_events')
                ->where('provider', 'billing')
                ->where('provider_event_id', 'evt_atomic_report_1')
                ->value('status') ?? '')
        );
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
        $this->assertSame(1, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
        Queue::assertPushed(GenerateReportPdfJob::class, 1);
    }
}
