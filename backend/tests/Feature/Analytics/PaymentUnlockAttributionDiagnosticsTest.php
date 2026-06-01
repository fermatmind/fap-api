<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\PaymentUnlockAttributionDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class PaymentUnlockAttributionDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_it_classifies_payment_to_unlock_attribution_gaps_without_writes(): void
    {
        $day = CarbonImmutable::parse('2026-05-31 10:00:00');
        $orgId = 0;
        $sku = 'MBTI_REPORT_FULL';

        $this->seedReportUnlockSku($sku);

        $readyAttempt = $this->seedAttemptRow($orgId, 'anon_ready', $day);
        $missingProjectionAttempt = $this->seedAttemptRow($orgId, 'anon_missing_projection', $day);
        $ownerMismatchAttempt = $this->seedAttemptRow($orgId, 'anon_owner_mismatch', $day);
        $postCommitAttempt = $this->seedAttemptRow($orgId, 'anon_post_commit', $day);
        $repairableAttempt = $this->seedAttemptRow($orgId, 'anon_repairable', $day);
        $pendingAttempt = $this->seedAttemptRow($orgId, 'anon_pending', $day);

        $this->seedOrder('ord_ready', $readyAttempt, $orgId, $sku, 'paid', 'granted', $day, $day->addMinute());
        $this->seedActiveGrant('ord_ready', $readyAttempt, $orgId, $day->addMinutes(2));
        $this->seedProjection($readyAttempt, 'ready', 'ready');

        $this->seedOrder('ord_projection_missing', $missingProjectionAttempt, $orgId, $sku, 'paid', 'granted', $day, $day->addMinute());
        $this->seedActiveGrant('ord_projection_missing', $missingProjectionAttempt, $orgId, $day->addMinutes(2));

        $this->seedOrder('ord_owner_mismatch', $ownerMismatchAttempt, $orgId, $sku, 'paid', 'not_started', $day, $day->addMinute());
        $this->seedPaymentEvent('ord_owner_mismatch', $orgId, 'rejected', 'ATTEMPT_OWNER_MISMATCH', $day->addMinutes(2));

        $this->seedOrder('ord_post_commit', $postCommitAttempt, $orgId, $sku, 'paid', 'grant_failed', $day, $day->addMinute());
        $this->seedPaymentEvent('ord_post_commit', $orgId, 'post_commit_failed', 'POST_COMMIT_FAILED', $day->addMinutes(2));

        $this->seedOrder('ord_repairable', $repairableAttempt, $orgId, $sku, 'paid', 'not_started', $day, $day->addMinute());
        $this->seedOrder('ord_pending', $pendingAttempt, $orgId, $sku, 'pending', 'not_started', $day, null);

        $before = $this->tableCounts();

        $summary = app(PaymentUnlockAttributionDiagnostics::class)->summarize(
            $day,
            $day,
            $orgId,
            50
        );

        $this->assertTrue($summary['read_only']);
        $this->assertFalse($summary['mutation_attempted']);
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame(6, $summary['inspected_orders']);

        $categories = $summary['categories'];
        $this->assertSame(1, $categories['paid_granted_projection_ready']);
        $this->assertSame(1, $categories['paid_granted_projection_missing']);
        $this->assertSame(1, $categories['paid_no_grant_owner_or_scale_mismatch']);
        $this->assertSame(1, $categories['paid_no_grant_post_commit_failed']);
        $this->assertSame(1, $categories['paid_no_grant_repairable_candidate']);
        $this->assertSame(1, $categories['payment_pending_client_presented']);

        $this->assertNotEmpty($summary['samples']);
        $this->assertStringStartsWith('sha256:', (string) $summary['samples'][0]['order_ref']);
        $this->assertStringNotContainsString('ord_', json_encode($summary['samples'], JSON_THROW_ON_ERROR));
    }

    public function test_it_reports_missing_source_tables_as_sidecar_without_querying_mutation_paths(): void
    {
        Schema::dropIfExists('unified_access_projections');

        $summary = app(PaymentUnlockAttributionDiagnostics::class)->summarize(
            new \DateTimeImmutable('2026-05-31'),
            new \DateTimeImmutable('2026-05-31'),
            0,
            10
        );

        $this->assertTrue($summary['read_only']);
        $this->assertFalse($summary['mutation_attempted']);
        $this->assertContains('unified_access_projections', $summary['missing_tables']);
        $this->assertContains('diagnostics_source_table_missing', $summary['sidecars']);
    }

    private function seedAttemptRow(int $orgId, string $anonId, CarbonImmutable $createdAt): string
    {
        $attemptId = (string) Str::uuid();

        $this->insertAttempt($attemptId, $orgId, 'en', $createdAt, $createdAt->addMinutes(5));

        DB::table('attempts')
            ->where('id', $attemptId)
            ->update(['anon_id' => $anonId]);

        return $attemptId;
    }

    private function seedReportUnlockSku(string $sku): void
    {
        $now = now();
        $row = [
            'sku' => $sku,
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'price_cents' => 299,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('skus', 'org_id')) {
            $row['org_id'] = 0;
        }

        DB::table('skus')->insert($row);
    }

    private function seedOrder(
        string $orderNo,
        string $attemptId,
        int $orgId,
        string $sku,
        string $paymentState,
        string $grantState,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $paidAt,
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => (string) DB::table('attempts')->where('id', $attemptId)->value('anon_id'),
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'currency' => 'CNY',
            'status' => $paymentState === 'paid' ? 'paid' : 'pending',
            'payment_state' => $paymentState,
            'grant_state' => $grantState,
            'provider' => 'alipay',
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'updated_at' => $paidAt ?? $createdAt,
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 299;
        }

        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = $sku;
        }

        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = $orderNo;
        }

        DB::table('orders')->insert($row);
    }

    private function seedActiveGrant(string $orderNo, string $attemptId, int $orgId, CarbonImmutable $createdAt): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'benefit_type' => 'report',
            'benefit_ref' => 'full',
            'order_no' => $orderNo,
            'source_order_id' => DB::table('orders')->where('order_no', $orderNo)->value('id'),
            'source_event_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedProjection(string $attemptId, string $accessState, string $reportState): void
    {
        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => 'missing',
            'reason_code' => 'entitlement_granted',
            'projection_version' => 1,
            'actions_json' => json_encode(['report' => true], JSON_THROW_ON_ERROR),
            'payload_json' => json_encode(['result_exists' => true], JSON_THROW_ON_ERROR),
            'produced_at' => now(),
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPaymentEvent(
        string $orderNo,
        int $orgId,
        string $status,
        string $lastErrorCode,
        CarbonImmutable $createdAt,
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'provider' => 'alipay',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(12)),
            'event_type' => 'payment_succeeded',
            'payload_json' => json_encode(['order_no' => $orderNo], JSON_THROW_ON_ERROR),
            'received_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'status' => $status,
            'handle_status' => $status,
            'last_error_code' => $lastErrorCode,
        ];

        if (Schema::hasColumn('payment_events', 'order_no')) {
            $row['order_no'] = $orderNo;
        }

        if (Schema::hasColumn('payment_events', 'order_id')) {
            $row['order_id'] = DB::table('orders')->where('order_no', $orderNo)->value('id');
        }

        DB::table('payment_events')->insert($row);
    }

    /**
     * @return array<string,int>
     */
    private function tableCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'payment_events' => DB::table('payment_events')->count(),
            'benefit_grants' => DB::table('benefit_grants')->count(),
            'unified_access_projections' => DB::table('unified_access_projections')->count(),
        ];
    }
}
