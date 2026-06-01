<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class AnalyticsFunnelDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_builder_builds_attempt_led_rows_with_stage_fallbacks_and_trailing_metrics(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(77);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [77],
        );

        $this->assertSame(2, (int) ($payload['attempted_rows'] ?? 0));

        $rowsByLocale = collect($payload['rows'])->keyBy('locale');

        $en = $rowsByLocale->get('en');
        $zh = $rowsByLocale->get('zh-CN');

        $this->assertNotNull($en);
        $this->assertNotNull($zh);

        $this->assertSame(2, (int) ($en['started_attempts'] ?? 0));
        $this->assertSame(2, (int) ($en['submitted_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['first_view_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['order_created_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['paid_attempts'] ?? 0));
        $this->assertSame(1299, (int) ($en['paid_revenue_cents'] ?? 0));
        $this->assertSame(1, (int) ($en['unlocked_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['report_ready_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['pdf_download_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['share_generated_attempts'] ?? 0));
        $this->assertSame(1, (int) ($en['share_click_attempts'] ?? 0));

        $this->assertSame(1, (int) ($zh['started_attempts'] ?? 0));
        $this->assertSame(1, (int) ($zh['submitted_attempts'] ?? 0));
        $this->assertSame(1, (int) ($zh['first_view_attempts'] ?? 0));
        $this->assertSame(1, (int) ($zh['order_created_attempts'] ?? 0));
        $this->assertSame(1, (int) ($zh['paid_attempts'] ?? 0));
        $this->assertSame(2599, (int) ($zh['paid_revenue_cents'] ?? 0));
        $this->assertSame(0, (int) ($zh['unlocked_attempts'] ?? 0));
        $this->assertSame(0, (int) ($zh['report_ready_attempts'] ?? 0));
    }

    public function test_builder_excludes_paywall_view_from_main_funnel_and_requires_active_grant_for_unlock(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(88);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [88],
        );

        $totals = collect($payload['rows'])->reduce(function (array $carry, array $row): array {
            foreach ([
                'started_attempts',
                'submitted_attempts',
                'first_view_attempts',
                'paid_attempts',
                'unlocked_attempts',
            ] as $metric) {
                $carry[$metric] = (int) ($carry[$metric] ?? 0) + (int) ($row[$metric] ?? 0);
            }

            return $carry;
        }, []);

        $this->assertSame(3, (int) ($totals['started_attempts'] ?? 0));
        $this->assertSame(3, (int) ($totals['submitted_attempts'] ?? 0));
        $this->assertSame(2, (int) ($totals['first_view_attempts'] ?? 0), 'paywall_view must stay outside the main view stage');
        $this->assertSame(2, (int) ($totals['paid_attempts'] ?? 0));
        $this->assertSame(1, (int) ($totals['unlocked_attempts'] ?? 0), 'unlock_success must depend on active grants, not paid order status');
    }

    public function test_projection_ready_paid_access_counts_as_report_ready(): void
    {
        $day = CarbonImmutable::parse('2026-02-02 09:00:00');

        $this->seedProjectionAccessAttempt(orgId: 101, day: $day);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [101],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['paid_attempts'] ?? 0));
        $this->assertSame(1, (int) ($row['unlocked_attempts'] ?? 0));
        $this->assertSame(1, (int) ($row['report_ready_attempts'] ?? 0));
    }

    public function test_projection_missing_does_not_count_as_report_ready(): void
    {
        $day = CarbonImmutable::parse('2026-02-03 09:00:00');

        $this->seedProjectionAccessAttempt(orgId: 102, day: $day, withProjection: false);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [102],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['unlocked_attempts'] ?? 0));
        $this->assertSame(0, (int) ($row['report_ready_attempts'] ?? 0));
    }

    public function test_grant_missing_does_not_count_projection_as_report_ready(): void
    {
        $day = CarbonImmutable::parse('2026-02-04 09:00:00');

        $this->seedProjectionAccessAttempt(orgId: 103, day: $day, withGrant: false);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [103],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(0, (int) ($row['unlocked_attempts'] ?? 0));
        $this->assertSame(0, (int) ($row['report_ready_attempts'] ?? 0));
    }

    public function test_snapshot_and_projection_ready_sources_count_once_per_attempt(): void
    {
        $day = CarbonImmutable::parse('2026-02-05 09:00:00');

        $this->seedProjectionAccessAttempt(orgId: 104, day: $day, withSnapshot: true);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [104],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['report_ready_attempts'] ?? 0));
    }

    public function test_projection_ready_time_wins_over_stale_snapshot_time(): void
    {
        $day = CarbonImmutable::parse('2026-02-07 09:00:00');

        $this->seedProjectionAccessAttempt(
            orgId: 105,
            day: $day,
            withSnapshot: true,
            snapshotAt: $day->addMinutes(12)
        );

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [105],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['paid_attempts'] ?? 0));
        $this->assertSame(1, (int) ($row['unlocked_attempts'] ?? 0));
        $this->assertSame(1, (int) ($row['report_ready_attempts'] ?? 0));
    }

    private function seedProjectionAccessAttempt(
        int $orgId,
        CarbonImmutable $day,
        bool $withProjection = true,
        bool $withGrant = true,
        bool $withSnapshot = false,
        ?CarbonImmutable $snapshotAt = null
    ): string {
        $attemptId = (string) Str::uuid();
        $orderNo = 'ord_projection_'.$orgId;

        $this->insertAttempt($attemptId, $orgId, 'en', $day, $day->addMinutes(5));
        $this->insertAttemptSubmission($attemptId, $orgId, $day->addMinutes(6));
        $this->insertResult($attemptId, $orgId, $day->addMinutes(8));
        $this->insertEvent($orgId, 'result_view', $attemptId, $day->addMinutes(10));
        $this->insertOrder($orderNo, $attemptId, $orgId, $day->addMinutes(20), 199, $day->addMinutes(25));

        if ($withGrant) {
            $this->insertBenefitGrant($attemptId, $orderNo, $orgId, $day->addMinutes(30));
        }

        $this->insertAttemptReceipt($attemptId, $day->addMinutes(35));

        if ($withProjection) {
            $this->insertUnifiedAccessProjection($attemptId, $day->addMinutes(40));
        }

        if ($withSnapshot) {
            $this->insertReportSnapshot($attemptId, $orderNo, $orgId, $snapshotAt ?? $day->addMinutes(45));
        }

        return $attemptId;
    }

    private function insertAttemptReceipt(string $attemptId, CarbonImmutable $recordedAt): void
    {
        DB::table('attempt_receipts')->insert([
            'attempt_id' => $attemptId,
            'seq' => 1,
            'receipt_type' => 'report_access_ready',
            'source_system' => 'analytics_test',
            'source_ref' => 'projection_ready_fixture',
            'actor_type' => 'system',
            'actor_id' => null,
            'idempotency_key' => 'receipt_'.$attemptId,
            'payload_json' => json_encode(['ready' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $recordedAt,
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);
    }

    private function insertUnifiedAccessProjection(string $attemptId, CarbonImmutable $readyAt): void
    {
        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'not_requested',
            'reason_code' => null,
            'projection_version' => 1,
            'actions_json' => json_encode(['enter_report' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode(['ready_to_enter' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'produced_at' => $readyAt,
            'refreshed_at' => $readyAt,
            'created_at' => $readyAt,
            'updated_at' => $readyAt,
        ]);
    }
}
