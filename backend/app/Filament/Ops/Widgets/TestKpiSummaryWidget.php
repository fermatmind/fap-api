<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\SchemaBaseline;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

final class TestKpiSummaryWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    private const GLOBAL_ORG_ID = 0;

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
            return [$this->emptyReadModelStat()];
        }

        $today = now()->toDateString();
        $query = DB::table('analytics_test_metrics_daily')
            ->where('org_id', self::GLOBAL_ORG_ID);

        $row = $query
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('SUM(CASE WHEN day = ? THEN successful_attempts ELSE 0 END) as today_successful_attempts', [$today])
            ->selectRaw('SUM(CASE WHEN day = ? THEN failed_attempts ELSE 0 END) as today_failed_attempts', [$today])
            ->selectRaw('SUM(successful_attempts) as cumulative_successful_attempts')
            ->selectRaw('SUM(failed_attempts) as cumulative_failed_attempts')
            ->first();

        if ((int) ($row->row_count ?? 0) <= 0) {
            return [$this->emptyReadModelStat()];
        }

        $todaySuccess = (int) ($row->today_successful_attempts ?? 0);
        $todayFailures = (int) ($row->today_failed_attempts ?? 0);
        $cumulativeSuccess = (int) ($row->cumulative_successful_attempts ?? 0);
        $cumulativeFailures = (int) ($row->cumulative_failed_attempts ?? 0);

        return [
            Stat::make(__('ops.widgets.test_success_today'), (string) $todaySuccess)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($todaySuccess > 0 ? 'success' : 'gray'),
            Stat::make(__('ops.widgets.test_failures_today'), (string) $todayFailures)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($todayFailures > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.site_success_cumulative'), (string) $cumulativeSuccess)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($cumulativeSuccess > 0 ? 'success' : 'gray'),
            Stat::make(__('ops.widgets.site_failures_cumulative'), (string) $cumulativeFailures)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($cumulativeFailures > 0 ? 'warning' : 'success'),
        ];
    }

    private function emptyReadModelStat(): Stat
    {
        return Stat::make(__('ops.widgets.test_kpi'), __('ops.widgets.no_data'))
            ->extraAttributes(['class' => 'ops-stat-centered'])
            ->color('gray');
    }
}
