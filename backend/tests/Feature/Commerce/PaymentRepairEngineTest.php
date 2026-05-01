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

    public function test_paid_order_repair_command_denies_attempt_owner_mismatch_without_event(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_paid_owner');
        $orderNo = 'ord_repair_paid_owner_mismatch_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_paid_other');

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
            '--order' => $orderNo,
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $summary = json_decode(Artisan::output(), true);
        $this->assertIsArray($summary);
        $this->assertSame(1, (int) ($summary['candidate_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['repaired_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['failed_count'] ?? -1));
        $this->assertSame('ATTEMPT_OWNER_MISMATCH', (string) ($summary['results'][0]['error'] ?? ''));

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
        ]);
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'action' => 'commerce_order_repair_failed',
            'target_type' => 'Order',
            'result' => 'failed',
        ]);
    }

    public function test_paid_order_repair_command_denies_attempt_scale_mismatch_without_event(): void
    {
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_paid_scale');
        DB::table('attempts')
            ->where('id', $attemptId)
            ->update([
                'scale_code' => 'SDS_20',
                'updated_at' => now()->subMinutes(9),
            ]);

        $orderNo = 'ord_repair_paid_scale_mismatch_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_paid_scale');

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
            '--order' => $orderNo,
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--json' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $summary = json_decode(Artisan::output(), true);
        $this->assertIsArray($summary);
        $this->assertSame(1, (int) ($summary['candidate_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['repaired_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['failed_count'] ?? -1));
        $this->assertSame('ATTEMPT_SCALE_MISMATCH', (string) ($summary['results'][0]['error'] ?? ''));

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'status' => 'paid',
        ]);
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
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

    public function test_post_commit_repair_command_reprocesses_provider_mismatch_after_provider_is_corrected(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_provider_repair');
        $orderNo = 'ord_repair_provider_mismatch_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_provider_repair');

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'provider' => 'stripe',
                'updated_at' => now()->subMinutes(10),
            ]);

        $processor = app(PaymentWebhookProcessor::class);
        $first = $processor->handle('billing', [
            'provider_event_id' => 'evt_repair_provider_mismatch_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_provider_mismatch_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_provider_repair', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_provider_mismatch_1',
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => 'PROVIDER_MISMATCH',
            'reason' => 'PROVIDER_MISMATCH',
        ]);

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'provider' => 'billing',
                'updated_at' => now()->subMinutes(9),
            ]);

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_repair_provider_mismatch_1',
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_post_commit_repair_command_reprocesses_legacy_provider_mismatch_after_provider_is_corrected(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_provider_repair_legacy');
        $orderNo = 'ord_repair_provider_mismatch_legacy_1';
        $providerEventId = 'evt_repair_provider_mismatch_legacy_1';
        $this->insertReportUnlockOrder($orderNo, $attemptId, 'anon_provider_repair_legacy');

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'provider' => 'billing',
                'updated_at' => now()->subMinutes(9),
            ]);

        $this->insertSemanticRejectPaymentEvent(
            orderNo: $orderNo,
            providerEventId: $providerEventId,
            status: 'rejected',
            errorCode: 'rejected_provider_mismatch',
            reason: 'REJECTED_PROVIDER_MISMATCH'
        );

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_post_commit_repair_command_reprocesses_rejected_sku_not_found_after_sku_is_restored(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_semantic_sku_fix');
        $orderNo = 'ord_repair_semantic_sku_1';
        $providerEventId = 'evt_repair_semantic_sku_1';
        $this->insertOrder($orderNo, 'SKU_REPAIR_MBTI_UNKNOWN', $attemptId, 'anon_semantic_sku_fix');

        $first = app(PaymentWebhookProcessor::class)->handle('billing', [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_semantic_sku_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_semantic_sku_fix', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => 'SKU_NOT_FOUND',
            'reason' => 'SKU_NOT_FOUND',
        ]);

        $this->upsertSku('SKU_REPAIR_MBTI_UNKNOWN', 'MBTI', 'report_unlock', 'MBTI_REPORT_FULL', 199, 'CNY');

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_post_commit_repair_command_reprocesses_rejected_benefit_code_missing_after_sku_is_corrected(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_semantic_benefit_fix');
        $orderNo = 'ord_repair_semantic_benefit_1';
        $providerEventId = 'evt_repair_semantic_benefit_1';
        $this->upsertSku('SKU_REPAIR_MBTI_NO_BENEFIT', 'MBTI', 'report_unlock', '', 199, 'CNY');
        $this->insertOrder($orderNo, 'SKU_REPAIR_MBTI_NO_BENEFIT', $attemptId, 'anon_semantic_benefit_fix');

        $first = app(PaymentWebhookProcessor::class)->handle('billing', [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_semantic_benefit_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_semantic_benefit_fix', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => 'BENEFIT_CODE_NOT_FOUND',
            'reason' => 'BENEFIT_CODE_NOT_FOUND',
        ]);

        DB::table('skus')
            ->where('sku', 'SKU_REPAIR_MBTI_NO_BENEFIT')
            ->update([
                'benefit_code' => 'MBTI_REPORT_FULL',
                'updated_at' => now()->subMinutes(9),
            ]);

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_post_commit_repair_command_reprocesses_rejected_attempt_required_after_attempt_is_backfilled(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $repairAttemptId = $this->insertAttempt('anon_semantic_attempt_fix');
        $orderNo = 'ord_repair_semantic_attempt_1';
        $providerEventId = 'evt_repair_semantic_attempt_1';
        $this->insertOrder($orderNo, 'MBTI_REPORT_FULL', null, 'anon_semantic_attempt_fix');

        $first = app(PaymentWebhookProcessor::class)->handle('billing', [
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_repair_semantic_attempt_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ], 0, null, 'anon_semantic_attempt_fix', true);

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'rejected',
            'handle_status' => 'rejected',
            'last_error_code' => 'ATTEMPT_REQUIRED',
            'reason' => 'ATTEMPT_REQUIRED',
        ]);

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update([
                'target_attempt_id' => $repairAttemptId,
                'updated_at' => now()->subMinutes(9),
            ]);

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--org_id' => 0,
            '--older_than_minutes' => 0,
            '--limit' => 20,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => $providerEventId,
            'status' => 'processed',
            'handle_status' => 'reprocessed',
        ]);
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'fulfilled',
        ]);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_post_commit_repair_command_reprocesses_legacy_failed_semantic_rejects_after_data_fix(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'sync',
        ]);

        (new Pr19CommerceSeeder)->run();

        $cases = [
            [
                'order_no' => 'ord_repair_legacy_failed_sku_1',
                'provider_event_id' => 'evt_repair_legacy_failed_sku_1',
                'error_code' => 'SKU_NOT_FOUND',
                'setup' => function (): void {
                    $attemptId = $this->insertAttempt('anon_legacy_failed_sku');
                    $this->insertOrder('ord_repair_legacy_failed_sku_1', 'SKU_REPAIR_MBTI_UNKNOWN_LEGACY', $attemptId, 'anon_legacy_failed_sku');
                    $this->upsertSku('SKU_REPAIR_MBTI_UNKNOWN_LEGACY', 'MBTI', 'report_unlock', 'MBTI_REPORT_FULL', 199, 'CNY');
                },
                'fix' => function (): void {},
                'payload_sku' => 'SKU_REPAIR_MBTI_UNKNOWN_LEGACY',
            ],
            [
                'order_no' => 'ord_repair_legacy_failed_benefit_1',
                'provider_event_id' => 'evt_repair_legacy_failed_benefit_1',
                'error_code' => 'BENEFIT_CODE_NOT_FOUND',
                'setup' => function (): void {
                    $attemptId = $this->insertAttempt('anon_legacy_failed_benefit');
                    $this->upsertSku('SKU_REPAIR_MBTI_NO_BENEFIT_LEGACY', 'MBTI', 'report_unlock', 'MBTI_REPORT_FULL', 199, 'CNY');
                    $this->insertOrder('ord_repair_legacy_failed_benefit_1', 'SKU_REPAIR_MBTI_NO_BENEFIT_LEGACY', $attemptId, 'anon_legacy_failed_benefit');
                },
                'fix' => function (): void {},
                'payload_sku' => 'SKU_REPAIR_MBTI_NO_BENEFIT_LEGACY',
            ],
            [
                'order_no' => 'ord_repair_legacy_failed_attempt_1',
                'provider_event_id' => 'evt_repair_legacy_failed_attempt_1',
                'error_code' => 'ATTEMPT_REQUIRED',
                'setup' => function (): void {
                    $this->insertOrder('ord_repair_legacy_failed_attempt_1', 'MBTI_REPORT_FULL', null, 'anon_legacy_failed_attempt');
                },
                'fix' => function (): void {
                    DB::table('orders')
                        ->where('order_no', 'ord_repair_legacy_failed_attempt_1')
                        ->update([
                            'target_attempt_id' => $this->insertAttempt('anon_legacy_failed_attempt'),
                            'updated_at' => now()->subMinutes(9),
                        ]);
                },
                'payload_sku' => 'MBTI_REPORT_FULL',
            ],
        ];

        foreach ($cases as $case) {
            $case['setup']();

            $this->insertSemanticRejectPaymentEvent(
                orderNo: $case['order_no'],
                providerEventId: $case['provider_event_id'],
                status: 'failed',
                errorCode: $case['error_code']
            );

            $case['fix']();

            $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
                '--org_id' => 0,
                '--older_than_minutes' => 0,
                '--limit' => 20,
            ]);
            $this->assertSame(0, $exitCode);

            $this->assertDatabaseHas('payment_events', [
                'provider_event_id' => $case['provider_event_id'],
                'status' => 'processed',
                'handle_status' => 'reprocessed',
            ]);
            $this->assertDatabaseHas('orders', [
                'order_no' => $case['order_no'],
                'payment_state' => 'paid',
                'grant_state' => 'granted',
                'status' => 'fulfilled',
            ]);
            $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $case['order_no'])->where('status', 'active')->count());
        }
    }

    public function test_paid_order_repair_command_skips_orders_blocked_by_unresolved_attempt_mismatch_semantic_rejects(): void
    {
        (new Pr19CommerceSeeder)->run();

        $cases = [
            [
                'code' => 'ATTEMPT_OWNER_MISMATCH',
                'order_no' => 'ord_paid_repair_owner_blocked_1',
                'provider_event_id' => 'evt_paid_repair_owner_blocked_1',
            ],
            [
                'code' => 'ATTEMPT_SCALE_MISMATCH',
                'order_no' => 'ord_paid_repair_scale_blocked_1',
                'provider_event_id' => 'evt_paid_repair_scale_blocked_1',
            ],
        ];

        foreach ($cases as $case) {
            $attemptId = $this->insertAttempt('anon_'.$case['code']);
            $this->insertReportUnlockOrder($case['order_no'], $attemptId, 'anon_'.$case['code']);

            DB::table('orders')
                ->where('order_no', $case['order_no'])
                ->update([
                    'status' => 'paid',
                    'payment_state' => 'paid',
                    'grant_state' => 'not_started',
                    'paid_at' => now()->subMinutes(10),
                    'updated_at' => now()->subMinutes(10),
                ]);

            $this->insertRejectedPaymentEvent($case['order_no'], $case['provider_event_id'], $case['code']);

            $exitCode = Artisan::call('commerce:repair-paid-orders', [
                '--org_id' => 0,
                '--order' => $case['order_no'],
                '--older_than_minutes' => 0,
                '--limit' => 20,
                '--json' => 1,
            ]);
            $this->assertSame(0, $exitCode);

            $summary = json_decode(Artisan::output(), true);
            $this->assertIsArray($summary);
            $this->assertSame(0, (int) ($summary['candidate_count'] ?? -1));
            $this->assertSame(0, (int) ($summary['repaired_count'] ?? -1));
            $this->assertSame(0, (int) ($summary['failed_count'] ?? -1));

            $this->assertDatabaseHas('orders', [
                'order_no' => $case['order_no'],
                'payment_state' => 'paid',
                'grant_state' => 'not_started',
                'status' => 'paid',
            ]);
            $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $case['order_no'])->count());
            $this->assertDatabaseHas('payment_events', [
                'provider_event_id' => $case['provider_event_id'],
                'status' => 'rejected',
                'last_error_code' => $case['code'],
            ]);
        }
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
        $this->insertOrder($orderNo, 'MBTI_REPORT_FULL', $attemptId, $anonId);
    }

    private function insertOrder(string $orderNo, string $sku, ?string $attemptId, string $anonId): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => $sku,
            'item_sku' => $sku,
            'effective_sku' => $sku,
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

    private function upsertSku(
        string $sku,
        string $scaleCode,
        string $kind,
        string $benefitCode,
        int $priceCents,
        string $currency
    ): void {
        DB::table('skus')->updateOrInsert(
            ['sku' => $sku],
            [
                'scale_code' => $scaleCode,
                'kind' => $kind,
                'unit_qty' => 1,
                'benefit_code' => $benefitCode,
                'scope' => 'attempt',
                'price_cents' => $priceCents,
                'currency' => $currency,
                'is_active' => true,
                'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]
        );
    }

    private function insertRejectedPaymentEvent(string $orderNo, string $providerEventId, string $errorCode): void
    {
        $this->insertSemanticRejectPaymentEvent($orderNo, $providerEventId, 'rejected', $errorCode);
    }

    private function insertSemanticRejectPaymentEvent(
        string $orderNo,
        string $providerEventId,
        string $status,
        string $errorCode,
        ?string $reason = null
    ): void {
        $orderId = (string) DB::table('orders')
            ->where('order_no', $orderNo)
            ->value('id');

        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'provider' => 'billing',
            'provider_event_id' => $providerEventId,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'event_type' => 'payment_succeeded',
            'status' => $status,
            'handle_status' => $status,
            'last_error_code' => $errorCode,
            'reason' => $reason ?? $errorCode,
            'attempts' => 0,
            'payload_json' => json_encode([
                'provider_event_id' => $providerEventId,
                'order_no' => $orderNo,
                'amount_cents' => 199,
                'currency' => 'CNY',
                'event_type' => 'payment_succeeded',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signature_ok' => true,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
    }
}
