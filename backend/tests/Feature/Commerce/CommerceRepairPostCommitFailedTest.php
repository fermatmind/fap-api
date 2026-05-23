<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\Commerce\ReprocessPaymentEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceRepairPostCommitFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_scope_queues_tenant_post_commit_failures(): void
    {
        Queue::fake();

        $orgId = 42;
        $eventId = (string) Str::uuid();
        $orderNo = 'ord_tenant_post_commit_default_scope_1';

        $this->insertOrder($orderNo, $orgId);
        $this->insertPostCommitFailedEvent($eventId, $orderNo, $orgId);

        $exitCode = Artisan::call('commerce:repair-post-commit-failed', [
            '--older_than_minutes' => 0,
            '--limit' => 20,
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $summary = json_decode(Artisan::output(), true);
        $this->assertIsArray($summary);
        $this->assertSame(1, (int) ($summary['candidate_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['queued_count'] ?? -1));
        $this->assertSame($orgId, (int) ($summary['results'][0]['effective_org_id'] ?? 0));

        $this->assertDatabaseHas('payment_events', [
            'id' => $eventId,
            'org_id' => $orgId,
            'handle_status' => 'queued',
        ]);

        Queue::assertPushed(
            ReprocessPaymentEventJob::class,
            fn (ReprocessPaymentEventJob $job): bool => $job->paymentEventId === $eventId
                && $job->orgId === $orgId
                && $job->reason === 'scheduled_payment_repair'
        );
    }

    private function insertOrder(string $orderNo, int $orgId): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => 'anon_tenant_post_commit_default_scope',
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => (string) Str::uuid(),
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'fulfilled',
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'provider' => 'billing',
            'provider_order_id' => null,
            'external_trade_no' => 'trade_tenant_post_commit_default_scope_1',
            'paid_at' => now()->subMinutes(10),
            'fulfilled_at' => now()->subMinutes(9),
            'refunded_at' => null,
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(8),
        ]);
    }

    private function insertPostCommitFailedEvent(string $eventId, string $orderNo, int $orgId): void
    {
        DB::table('payment_events')->insert([
            'id' => $eventId,
            'org_id' => $orgId,
            'provider' => 'billing',
            'provider_event_id' => 'evt_tenant_post_commit_default_scope_1',
            'order_id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'event_type' => 'payment_succeeded',
            'status' => 'post_commit_failed',
            'handle_status' => 'post_commit_failed',
            'last_error_code' => 'REPORT_SNAPSHOT_SEED_FAILED',
            'reason' => 'REPORT_SNAPSHOT_SEED_FAILED',
            'attempts' => 1,
            'payload_json' => json_encode([
                'provider_event_id' => 'evt_tenant_post_commit_default_scope_1',
                'order_no' => $orderNo,
                'external_trade_no' => 'trade_tenant_post_commit_default_scope_1',
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
