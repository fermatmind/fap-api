<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class RefreshAnalyticsFunnelDailyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_command_supports_dry_run_and_upserts_refresh_scope(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(91);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=2')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=91:scale=all',
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_rows=2')
            ->expectsOutputToContain('write_guard=passed')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('analytics_funnel_daily')->count());

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=91:scale=all',
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('analytics_funnel_daily')->count());
        $this->assertSame(
            3898,
            (int) DB::table('analytics_funnel_daily')->where('org_id', 91)->sum('paid_revenue_cents')
        );
    }

    public function test_dry_run_reports_projection_ready_attempt_without_writing(): void
    {
        $day = CarbonImmutable::parse('2026-02-06 09:00:00');

        $this->seedProjectionAccessAttempt(92, $day);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $day->toDateString(),
            '--to' => $day->toDateString(),
            '--org' => [92],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=1')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());

        $payload = app(\App\Services\Analytics\AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($day->toDateString()),
            new \DateTimeImmutable($day->toDateString()),
            [92],
        );

        $row = collect($payload['rows'])->firstWhere('locale', 'en');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) ($row['report_ready_attempts'] ?? 0));
    }

    private function seedProjectionAccessAttempt(int $orgId, CarbonImmutable $day): string
    {
        $attemptId = (string) Str::uuid();
        $orderNo = 'ord_projection_refresh_'.$orgId;

        $this->insertAttempt($attemptId, $orgId, 'en', $day, $day->addMinutes(5));
        $this->insertAttemptSubmission($attemptId, $orgId, $day->addMinutes(6));
        $this->insertResult($attemptId, $orgId, $day->addMinutes(8));
        $this->insertEvent($orgId, 'result_view', $attemptId, $day->addMinutes(10));
        $this->insertOrder($orderNo, $attemptId, $orgId, $day->addMinutes(20), 199, $day->addMinutes(25));
        $this->insertBenefitGrant($attemptId, $orderNo, $orgId, $day->addMinutes(30));
        $this->insertAttemptReceipt($attemptId, $day->addMinutes(35));
        $this->insertUnifiedAccessProjection($attemptId, $day->addMinutes(40));

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
