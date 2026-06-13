<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

final class TestKpiSummaryWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    private const NO_ORG_PLACEHOLDER = '-';

    private const EMPTY_READ_MODEL_MESSAGE = 'No analytics_test_metrics_daily rows match the current org. Run analytics:refresh-test-metrics-daily in a controlled task.';

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    protected function getHeading(): ?string
    {
        return __('ops.widgets.test_kpi_overview');
    }

    protected function getStats(): array
    {
        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            return [$this->emptyReadModelStat('analytics_test_metrics_daily table is missing. Run php artisan migrate first.')];
        }

        $orgContext = app(OrgContext::class);
        $orgId = max(0, (int) $orgContext->orgId());
        if ($orgId <= 0 && $orgContext->isTenantContext()) {
            return [
                $this->noOrgStat(__('ops.widgets.test_success_today'), __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat(__('ops.widgets.test_failures_today'), __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat(__('ops.widgets.site_success_cumulative'), __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat(__('ops.widgets.site_failures_cumulative'), __('ops.widgets.select_org_to_view_metrics')),
            ];
        }

        $today = now()->toDateString();
        $query = DB::table('analytics_test_metrics_daily')
            ->where('org_id', $orgId);

        $row = $query
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('SUM(CASE WHEN day = ? THEN successful_attempts ELSE 0 END) as today_successful_attempts', [$today])
            ->selectRaw('SUM(CASE WHEN day = ? THEN failed_attempts ELSE 0 END) as today_failed_attempts', [$today])
            ->selectRaw('SUM(successful_attempts) as cumulative_successful_attempts')
            ->selectRaw('SUM(failed_attempts) as cumulative_failed_attempts')
            ->first();

        if ((int) ($row->row_count ?? 0) <= 0) {
            return [$this->emptyReadModelStat(self::EMPTY_READ_MODEL_MESSAGE)];
        }

        $todaySuccess = (int) ($row->today_successful_attempts ?? 0);
        $todayFailures = (int) ($row->today_failed_attempts ?? 0);
        $cumulativeSuccess = (int) ($row->cumulative_successful_attempts ?? 0);
        $cumulativeFailures = (int) ($row->cumulative_failed_attempts ?? 0);

        return [
            Stat::make(__('ops.widgets.test_success_today'), (string) $todaySuccess)
                ->description('analytics_test_metrics_daily.successful_attempts today')
                ->color($todaySuccess > 0 ? 'success' : 'gray'),
            Stat::make(__('ops.widgets.test_failures_today'), (string) $todayFailures)
                ->description('analytics_test_metrics_daily.failed_attempts today')
                ->color($todayFailures > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.site_success_cumulative'), (string) $cumulativeSuccess)
                ->description('analytics_test_metrics_daily.successful_attempts all days')
                ->color($cumulativeSuccess > 0 ? 'success' : 'gray'),
            Stat::make(__('ops.widgets.site_failures_cumulative'), (string) $cumulativeFailures)
                ->description('analytics_test_metrics_daily.failed_attempts all days')
                ->color($cumulativeFailures > 0 ? 'warning' : 'success'),
        ];
    }

    private function noOrgStat(string $label, string $description): Stat
    {
        return Stat::make($label, self::NO_ORG_PLACEHOLDER)
            ->description($description)
            ->color('gray');
    }

    private function emptyReadModelStat(string $description): Stat
    {
        return Stat::make(__('ops.widgets.test_kpi'), __('ops.widgets.no_data'))
            ->description($description)
            ->color('gray');
    }
}
