<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class AnalyticsFunnelRefreshDryRunSqlFix02Test extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_share_click_counts_when_event_attempt_id_is_present(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(247);
        $day = CarbonImmutable::parse($scenario['day'].' 08:00:00');

        $this->insertShareClickEvent(247, $scenario['attempt_b'], $day->addHours(3)->addMinutes(35));

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [247],
        );

        $shareClicks = collect($payload['rows'])->sum(
            static fn (array $row): int => (int) ($row['share_click_attempts'] ?? 0)
        );

        $this->assertSame(2, $shareClicks);
    }

    public function test_share_click_counts_through_share_attempt_fallback_when_event_attempt_id_is_absent(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(248);

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [248],
        );

        $rowsByLocale = collect($payload['rows'])->keyBy('locale');

        $this->assertSame(1, (int) ($rowsByLocale->get('en')['share_click_attempts'] ?? 0));
    }

    public function test_unrelated_share_click_events_do_not_count(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(249);
        $day = CarbonImmutable::parse($scenario['day'].' 08:00:00');

        $this->insertShareClickEvent(249, null, $day->addHours(2));
        $this->insertShareClickEvent(250, $scenario['attempt_b'], $day->addHours(3)->addMinutes(35));

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [249],
        );

        $shareClicks = collect($payload['rows'])->sum(
            static fn (array $row): int => (int) ($row['share_click_attempts'] ?? 0)
        );

        $this->assertSame(1, $shareClicks);
    }

    public function test_share_click_query_no_longer_uses_events_attempt_id_in_having_context(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(251);
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [251],
        );

        $combinedSql = implode("\n", $queries);

        $this->assertStringContainsString('share_click_events', $combinedSql);
        $this->assertStringNotContainsString('having coalesce(events.attempt_id', $combinedSql);
        $this->assertDoesNotMatchRegularExpression(
            '/having[^\n]*(?:`?events`?\s*\.\s*`?attempt_id`?|events\.attempt_id|`?shares`?\s*\.\s*`?attempt_id`?|shares\.attempt_id)/',
            $combinedSql
        );
    }

    public function test_dry_run_refresh_completes_past_share_click_stage_without_writing_rows(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(252);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [252],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=2')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_builder_queries_do_not_emit_table_qualified_attempt_id_having_shapes(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(253);
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [253],
        );

        $combinedSql = implode("\n", $queries);

        $this->assertDoesNotMatchRegularExpression(
            '/having[^\n]*(?:`?events`?\s*\.\s*`?attempt_id`?|events\.attempt_id|`?shares`?\s*\.\s*`?attempt_id`?|shares\.attempt_id|`?benefit_grants`?\s*\.\s*`?attempt_id`?|benefit_grants\.attempt_id|`?orders`?\s*\.\s*`?target_attempt_id`?|orders\.target_attempt_id)/',
            $combinedSql
        );
    }

    private function insertShareClickEvent(
        int $orgId,
        ?string $attemptId,
        CarbonImmutable $occurredAt,
        ?string $shareId = null,
    ): void {
        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => 'share_click',
            'event_name' => 'share_click',
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => $attemptId ? 'anon_'.$attemptId : null,
            'session_id' => 'session_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 8),
            'request_id' => 'req_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 12),
            'attempt_id' => $attemptId,
            'meta_json' => json_encode(['attempt_id' => $attemptId, 'share_id' => $shareId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'share_id' => $shareId,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'region' => 'US',
            'locale' => 'en',
        ]);
    }
}
