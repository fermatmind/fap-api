<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\Commerce\ReprocessPaymentEventJob;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Services\Commerce\Repair\OrderRepairService;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PaymentRepairEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reprocess_repairs_paid_order_when_initial_grant_failed(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_grant_failed');
        $orderNo = 'ord_repair_grant_failed_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_grant_failed');

        $realEntitlements = app(EntitlementManager::class);
        $entitlements = Mockery::mock(EntitlementManager::class)->makePartial();
        $grantCalls = 0;
        $entitlements->shouldReceive('grantAttemptUnlock')
            ->twice()
            ->andReturnUsing(function (
                int $orgId,
                ?string $userId,
                ?string $anonId,
                string $benefitCode,
                string $attemptIdArg,
                ?string $orderNoArg,
                ?string $scopeOverride = null,
                ?string $expiresAt = null,
                ?array $modules = null
            ) use (&$grantCalls, $realEntitlements) {
                $grantCalls++;
                if ($grantCalls === 1) {
                    return [
                        'ok' => false,
                        'error' => 'SIMULATED_GRANT_FAILURE',
                        'message' => 'simulated grant failure',
                    ];
                }

                return $realEntitlements->grantAttemptUnlock(
                    $orgId,
                    $userId,
                    $anonId,
                    $benefitCode,
                    $attemptIdArg,
                    $orderNoArg,
                    $scopeOverride,
                    $expiresAt,
                    $modules
                );
            });
        $this->app->instance(EntitlementManager::class, $entitlements);

        $processor = app(PaymentWebhookProcessor::class);
        $first = $processor->handle('billing', [
            'provider_event_id' => 'evt_repair_grant_failed_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_grant_failed_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_grant_failed', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'grant_failed',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'billing',
            'provider_event_id' => 'evt_repair_grant_failed_1',
            'status' => 'failed',
        ]);
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $orderNo)->count());

        $eventId = (string) DB::table('payment_events')
            ->where('provider_event_id', 'evt_repair_grant_failed_1')
            ->value('id');

        $job = new ReprocessPaymentEventJob($eventId, 0, 'grant_failed_repair', 'corr-grant-failed-1');
        $job->handle(app(PaymentWebhookProcessor::class), app(OrderRepairService::class));

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
        $this->assertDatabaseHas('payment_events', [
            'id' => $eventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'commerce_order_repair_skipped',
            'target_type' => 'Order',
        ]);
    }

    public function test_post_commit_repair_command_reprocesses_failed_event(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_post_commit');
        $orderNo = 'ord_repair_post_commit_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_post_commit');

        $realSnapshotStore = app(ReportSnapshotStore::class);
        $snapshotStore = Mockery::mock(ReportSnapshotStore::class)->makePartial();
        $seedCalls = 0;
        $snapshotStore->shouldReceive('seedPendingSnapshot')
            ->twice()
            ->andReturnUsing(function (int $orgId, string $attemptIdArg, ?string $orderNoArg, array $meta) use (&$seedCalls, $realSnapshotStore): void {
                $seedCalls++;
                if ($seedCalls === 1) {
                    throw new \RuntimeException('simulated_post_commit_failure');
                }

                $realSnapshotStore->seedPendingSnapshot($orgId, $attemptIdArg, $orderNoArg, $meta);
            });
        $this->app->instance(ReportSnapshotStore::class, $snapshotStore);

        $processor = app(PaymentWebhookProcessor::class);
        $first = $processor->handle('billing', [
            'provider_event_id' => 'evt_repair_post_commit_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_post_commit_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_post_commit', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_post_commit_1',
            'status' => 'post_commit_failed',
        ]);
        $this->assertSame(0, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_post_commit_1',
            'status' => 'processed',
        ]);
        $this->assertSame(1, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
    }

    public function test_paid_order_repair_command_repairs_paid_order_without_event(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_paid_command');
        $orderNo = 'ord_repair_paid_command_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_paid_command');

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'status' => 'paid',
                'payment_state' => 'paid',
                'updated_at' => now()->subMinutes(10),
                'paid_at' => now()->subMinutes(10),
            ]);

        $exitCode = Artisan::call('commerce:repair-paid-orders', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_paid_order_repair_ignores_unrelated_active_grant_and_grants_correct_benefit(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_paid_wrong_grant');
        $orderNo = 'ord_repair_wrong_grant_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_paid_wrong_grant');

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'status' => 'paid',
                'payment_state' => 'paid',
                'updated_at' => now()->subMinutes(10),
                'paid_at' => now()->subMinutes(10),
            ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => 'anon_paid_wrong_grant',
            'benefit_code' => 'OTHER_BENEFIT',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'status' => 'active',
            'benefit_ref' => 'anon_paid_wrong_grant',
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => null,
            'meta_json' => null,
            'created_at' => now()->subMinutes(9),
            'updated_at' => now()->subMinutes(9),
        ]);

        $exitCode = Artisan::call('commerce:repair-paid-orders', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertSame(2, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
        $this->assertDatabaseHas('benefit_grants', [
            'order_no' => $orderNo,
            'attempt_id' => $attemptId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'status' => 'active',
        ]);
    }

    public function test_semantic_reject_event_can_be_repaired_after_order_exists(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $orderNo = 'ord_repair_orphan_1';
        $processor = app(PaymentWebhookProcessor::class);
        $first = $processor->handle('billing', [
            'provider_event_id' => 'evt_repair_orphan_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_orphan_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_repair_orphan', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_orphan_1',
            'status' => 'orphan',
            'last_error_code' => 'ORDER_NOT_FOUND',
        ]);

        $attemptId = $this->insertAttempt('anon_repair_orphan');
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_repair_orphan');

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_orphan_1',
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    private function insertAttempt(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(9),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(9),
        ]);

        return $attemptId;
    }

    private function insertReportUnlockOrder(string $orderNo, string $attemptId, string $anonId): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'created',
            'payment_state' => 'created',
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'provider_order_id' => null,
            'external_trade_no' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);
    }
}
