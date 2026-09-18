<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\SchemaBaseline;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

final class AccessTestStatisticsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('ops.widgets.access_test_statistics');
    }

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    protected function getStats(): array
    {
        if (! SchemaBaseline::hasTable('analytics_access_test_daily')) {
            return [Stat::make(__('ops.widgets.access_test_statistics'), __('ops.widgets.no_data'))->color('gray')];
        }

        $row = DB::table('analytics_access_test_daily')
            ->where('org_id', 0)
            ->where('day', now('Asia/Shanghai')->toDateString())
            ->where('scale_code', '*')
            ->where('form_code', '*')
            ->where('locale', '*')
            ->first();

        if ($row === null) {
            return [Stat::make(__('ops.widgets.access_test_statistics'), __('ops.widgets.no_data'))->color('gray')];
        }

        $description = __('ops.widgets.access_test_status', [
            'status' => (string) $row->coverage_status,
            'time' => (string) ($row->last_successful_refresh_at ?? ''),
        ]);

        return [
            Stat::make(__('ops.pages.access_test_statistics.metrics.valid_visit_ips'), (string) $row->valid_visit_ips)->description($description),
            Stat::make(__('ops.pages.access_test_statistics.metrics.started_test_ips'), (string) $row->started_test_ips),
            Stat::make(__('ops.pages.access_test_statistics.metrics.completed_test_ips'), (string) $row->completed_test_ips),
            Stat::make(__('ops.pages.access_test_statistics.metrics.successful_attempts'), (string) $row->successful_attempts),
            Stat::make(__('ops.pages.access_test_statistics.metrics.valid_page_views'), (string) $row->valid_page_views),
        ];
    }
}
