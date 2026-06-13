<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\TestKpiSummaryWidget;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class TestKpiSummaryWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_reads_sitewide_org_zero_success_failure_regardless_of_selected_org(): void
    {
        Carbon::setTestNow('2026-06-20 10:00:00');

        try {
            $this->setOpsOrg(21);

            $this->insertDailyMetric('2026-06-19', 0, 'MBTI', 'zh-CN', 5, 3, 8);
            $this->insertDailyMetric('2026-06-20', 0, 'MBTI', 'zh-CN', 7, 2, 9);
            $this->insertDailyMetric('2026-06-20', 0, 'BIG5_OCEAN', 'en', 11, 1, 12);
            $this->insertDailyMetric('2026-06-20', 21, 'MBTI', 'zh-CN', 100, 100, 200);

            $valuesByLabel = [];
            foreach ($this->widgetStats() as $stat) {
                $valuesByLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
            }

            $this->assertSame('18', $valuesByLabel[__('ops.widgets.test_success_today')] ?? null);
            $this->assertSame('3', $valuesByLabel[__('ops.widgets.test_failures_today')] ?? null);
            $this->assertSame('23', $valuesByLabel[__('ops.widgets.site_success_cumulative')] ?? null);
            $this->assertSame('6', $valuesByLabel[__('ops.widgets.site_failures_cumulative')] ?? null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_widget_does_not_fallback_to_raw_attempts_when_read_model_is_empty(): void
    {
        Carbon::setTestNow('2026-06-20 10:00:00');

        try {
            $this->setOpsOrg(21);

            DB::table('attempts')->insert([
                'id' => (string) Str::uuid(),
                'anon_id' => 'anon_test_kpi_widget',
                'user_id' => null,
                'org_id' => 21,
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'question_count' => 100,
                'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'client_platform' => 'web',
                'client_version' => 'test',
                'channel' => 'web',
                'referrer' => '/tests/test-kpi',
                'locale' => 'zh-CN',
                'started_at' => now(),
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stats = $this->widgetStats();

            $this->assertCount(1, $stats);
            $this->assertSame(__('ops.widgets.test_kpi'), (string) $stats[0]->getLabel());
            $this->assertSame(__('ops.widgets.no_data'), (string) $stats[0]->getValue());
            $this->assertSame(
                'No analytics_test_metrics_daily rows match global org_id=0. Run analytics:refresh-test-metrics-daily in a controlled task.',
                (string) $stats[0]->getDescription()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function setOpsOrg(int $orgId): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
    }

    private function insertDailyMetric(
        string $day,
        int $orgId,
        string $scaleCode,
        string $locale,
        int $successfulAttempts,
        int $failedAttempts,
        int $totalAttempts,
    ): void {
        DB::table('analytics_test_metrics_daily')->insert([
            'day' => $day,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'scale_code_v2' => $scaleCode,
            'scale_uid' => '',
            'form_code' => '',
            'locale' => $locale,
            'started_attempts' => $totalAttempts,
            'successful_attempts' => $successfulAttempts,
            'failed_attempts' => $failedAttempts,
            'total_attempts' => $totalAttempts,
            'last_refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<object>
     */
    private function widgetStats(): array
    {
        $widget = app(TestKpiSummaryWidget::class);
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }
}
