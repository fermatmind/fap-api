<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class AnalyticsFunnelRefreshDryRunSqlFix01Test extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_active_benefit_grant_counts_as_report_unlock_while_inactive_expired_and_unrelated_grants_do_not(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(144);
        $day = CarbonImmutable::parse($scenario['day'].' 08:00:00');

        $this->insertGrantForSqlFix(
            orgId: 144,
            attemptId: $scenario['attempt_b'],
            createdAt: $day->addHours(3)->addMinutes(45),
            status: 'inactive',
        );
        $this->insertGrantForSqlFix(
            orgId: 144,
            attemptId: $scenario['attempt_c'],
            createdAt: $day->addHours(4)->addMinutes(45),
            status: 'active',
            expiresAt: $day->subDay(),
        );
        $this->insertGrantForSqlFix(
            orgId: 144,
            attemptId: null,
            createdAt: $day->addHours(5),
            status: 'active',
        );

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [144],
        );

        $unlockedAttempts = collect($payload['rows'])->sum(
            static fn (array $row): int => (int) ($row['unlocked_attempts'] ?? 0)
        );

        $this->assertSame(1, $unlockedAttempts);
    }

    public function test_dry_run_refresh_completes_and_writes_no_analytics_funnel_daily_rows(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(145);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [145],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=2')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_unlock_query_no_longer_uses_benefit_grants_attempt_id_in_having_context(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(146);
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [146],
        );

        $combinedSql = implode("\n", $queries);

        $this->assertStringNotContainsString(
            'having coalesce(benefit_grants.attempt_id',
            $combinedSql
        );
        $this->assertStringContainsString('from (select', $combinedSql);
        $this->assertStringContainsString('benefit_grants', $combinedSql);
    }

    private function insertGrantForSqlFix(
        int $orgId,
        ?string $attemptId,
        CarbonImmutable $createdAt,
        string $status,
        ?CarbonImmutable $expiresAt = null,
    ): void {
        $row = [
            'id' => $grantId = (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => $status,
            'benefit_type' => 'report',
            'benefit_ref' => 'full_'.$grantId,
            'order_no' => null,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (! Schema::hasColumn('benefit_grants', 'expires_at')) {
            unset($row['expires_at']);
        }

        DB::table('benefit_grants')->insert($row);
    }
}
