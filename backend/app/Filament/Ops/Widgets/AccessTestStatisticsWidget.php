<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Services\Analytics\SiteTrafficSnapshot;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
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
            return [
                Stat::make(__('ops.widgets.access_test_statistics'), __('ops.widgets.no_data'))->color('gray'),
                ...$this->siteTrafficStats(),
            ];
        }

        $row = DB::table('analytics_access_test_daily')
            ->where('org_id', 0)
            ->where('day', now('Asia/Shanghai')->toDateString())
            ->where('scale_code', '*')
            ->where('form_code', '*')
            ->where('locale', '*')
            ->first();

        if ($row === null) {
            return [
                Stat::make(__('ops.widgets.access_test_statistics'), __('ops.widgets.no_data'))->color('gray'),
                ...$this->siteTrafficStats(),
            ];
        }

        $description = __('ops.widgets.access_test_status', [
            'status' => (string) $row->coverage_status,
            'time' => empty($row->last_successful_refresh_at) ? '' : CarbonImmutable::parse((string) $row->last_successful_refresh_at, 'UTC')
                ->setTimezone('Asia/Shanghai')
                ->format('Y-m-d H:i:s').' (UTC+08:00)',
        ]);

        return [
            Stat::make(__('ops.pages.access_test_statistics.metrics.valid_visit_ips'), (string) $row->valid_visit_ips)->description($description),
            Stat::make(__('ops.pages.access_test_statistics.metrics.started_test_ips'), (string) $row->started_test_ips),
            Stat::make(__('ops.pages.access_test_statistics.metrics.completed_test_ips'), (string) $row->completed_test_ips),
            Stat::make(__('ops.pages.access_test_statistics.metrics.successful_attempts'), (string) $row->successful_attempts),
            Stat::make(__('ops.pages.access_test_statistics.metrics.valid_page_views'), (string) $row->valid_page_views),
            ...$this->siteTrafficStats(),
        ];
    }

    /** @return list<Stat> */
    private function siteTrafficStats(): array
    {
        $snapshot = app(SiteTrafficSnapshot::class)->today();
        $description = $snapshot === null
            ? __('ops.widgets.site_traffic_unavailable')
            : __('ops.widgets.site_traffic_through', [
                'time' => CarbonImmutable::createFromTimestamp($snapshot['through'], 'Asia/Shanghai')->format('H:i:s'),
            ]);

        $uniqueBrowsers = $snapshot['uniqueBrowsers'] ?? null;
        $browserDescription = $uniqueBrowsers === null
            ? __('ops.widgets.site_traffic_browsers_unavailable')
            : __('ops.widgets.site_traffic_browsers_coverage', [
                'covered' => number_format($snapshot['identifiedPageRequests']),
                'total' => number_format($snapshot['pageRequests']),
            ]).' · '.$description;

        return [
            Stat::make(__('ops.widgets.site_traffic_browsers'), $uniqueBrowsers === null ? '—' : number_format($uniqueBrowsers))
                ->description($browserDescription),
            Stat::make(__('ops.widgets.site_traffic_requests'), $snapshot === null ? '—' : number_format($snapshot['pageRequests']))
                ->description($description),
            Stat::make(__('ops.widgets.site_traffic_ips'), $snapshot === null ? '—' : number_format($snapshot['uniqueIps']))
                ->description($description),
        ];
    }
}
