<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Models\AdminApproval;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QueueFailureWidget extends BaseWidget
{
    protected ?string $heading = 'Stability and Risk';

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
            Stat::make('failed_jobs count', (string) $failedJobsCount)
                ->description('Queue failure backlog')
                ->color($failedJobsCount > 0 ? 'danger' : 'success'),
            Stat::make('pending approvals', (string) $pendingApprovals)
                ->description('Risk badge: pending high-risk actions')
                ->color($pendingApprovals > 0 ? 'warning' : 'success'),
        ];
    }
}
