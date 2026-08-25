<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WebhookFailureWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewCommerceMetrics();
    }

    protected function getHeading(): ?string
    {
        return __('ops.widgets.webhook_monitoring');
    }

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        if ($orgId <= 0) {
            return [
                Stat::make(__('ops.widgets.webhook_failures_15m'), '0')->extraAttributes(['class' => 'ops-stat-centered']),
                Stat::make(__('ops.widgets.webhook_failures_all'), '0')->extraAttributes(['class' => 'ops-stat-centered']),
            ];
        }

        $windowStart = now()->subMinutes(15);

        $failures15m = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('created_at', '>=', $windowStart)
            ->where(function ($query): void {
                $query->where('signature_ok', 0)
                    ->orWhereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                    ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
            })
            ->count();

        $signatureFailed = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('signature_ok', 0)
            ->count();

        $processedFailed = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                    ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
            })
            ->count();

        return [
            Stat::make(__('ops.widgets.webhook_failures_15m'), (string) $failures15m)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($failures15m > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.webhook_failures_all'), (string) ($signatureFailed + $processedFailed))
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color(($signatureFailed + $processedFailed) > 0 ? 'danger' : 'success'),
        ];
    }
}
