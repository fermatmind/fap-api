<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
