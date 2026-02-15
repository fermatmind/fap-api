<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Models\AdminApproval;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QueueFailureWidget extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ops.widgets.stability_risk');
    }

    protected function getStats(): array
    {
        $failedJobsCount = 0;
        if (\App\Support\SchemaBaseline::hasTable('failed_jobs')) {
            $failedJobsCount = (int) DB::table('failed_jobs')->count();
        }

        $pendingApprovals = 0;
        if (\App\Support\SchemaBaseline::hasTable('admin_approvals')) {
            $pendingApprovals = (int) DB::table('admin_approvals')
                ->where('status', AdminApproval::STATUS_PENDING)
                ->count();
        }

        return [
            Stat::make(__('ops.widgets.failed_jobs_count'), (string) $failedJobsCount)
                ->description(__('ops.widgets.queue_failure_backlog'))
                ->color($failedJobsCount > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.pending_approvals'), (string) $pendingApprovals)
                ->description(__('ops.widgets.risk_badge_pending_approvals'))
                ->color($pendingApprovals > 0 ? 'warning' : 'success'),
        ];
    }
}
