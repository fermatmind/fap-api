<?php

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FunnelWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    private const EMPTY_READ_MODEL_MESSAGE = 'No analytics_funnel_daily rows for this range. Run analytics:refresh-funnel-daily in a controlled task.';

    /** @var array<string,string> */
    private const CANONICAL_STAGES = [
        'test_start' => 'started_attempts',
        'test_submit' => 'submitted_attempts',
        'result_view' => 'first_view_attempts',
        'order_created' => 'order_created_attempts',
        'payment_success' => 'paid_attempts',
        'report_unlock' => 'unlocked_attempts',
        'report_ready' => 'report_ready_attempts',
        'pdf_download' => 'pdf_download_attempts',
        'share_generate' => 'share_generated_attempts',
        'share_click' => 'share_click_attempts',
    ];

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewCommerceMetrics();
    }

    protected function getHeading(): ?string
    {
        return __('ops.widgets.funnel_snapshot_7d');
    }

    protected function getStats(): array
    {
        if (! SchemaBaseline::hasTable('analytics_funnel_daily')) {
            return [$this->emptyReadModelStat()];
        }

        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        if ($orgId <= 0) {
            return [
                Stat::make(__('ops.widgets.funnel'), __('ops.widgets.no_data'))
                    ->description(__('ops.widgets.select_org_to_view_metrics'))
                    ->color('gray'),
            ];
        }

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $query = DB::table('analytics_funnel_daily')
            ->where('org_id', $orgId)
            ->whereBetween('day', [$from, $to]);

        $selects = ['COUNT(*) as row_count'];
        foreach (self::CANONICAL_STAGES as $metric) {
            $selects[] = 'SUM('.$metric.') as '.$metric;
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();
        if ((int) ($row->row_count ?? 0) <= 0) {
            return [$this->emptyReadModelStat()];
        }

        $stats = [];
        foreach (self::CANONICAL_STAGES as $stage => $metric) {
            $stats[] = Stat::make($stage, (string) ((int) ($row->{$metric} ?? 0)))
                ->description('analytics_funnel_daily.'.$metric);
        }

        return $stats;
    }

    private function emptyReadModelStat(): Stat
    {
        return Stat::make(__('ops.widgets.funnel'), __('ops.widgets.no_data'))
            ->description(self::EMPTY_READ_MODEL_MESSAGE)
            ->color('gray');
    }
}
