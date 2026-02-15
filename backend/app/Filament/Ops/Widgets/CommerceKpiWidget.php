<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CommerceKpiWidget extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ops.widgets.today_business');
    }

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $start = now()->startOfDay();
        $end = (clone $start)->addDay();

        if ($orgId <= 0) {
            return [
                Stat::make(__('ops.widgets.paid_orders_today'), '0')->description(__('ops.widgets.select_org_to_view_metrics')),
                Stat::make(__('ops.widgets.revenue_today'), '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make(__('ops.widgets.unlock_rate'), '0%')->description(__('ops.widgets.no_org_selected')),
                Stat::make(__('ops.widgets.refund_count'), '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make(__('ops.widgets.webhook_failures'), '0')->description(__('ops.widgets.no_org_selected')),
            ];
        }

        $paidOrders = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('status', ['paid', 'fulfilled', 'refunded'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();

        $todayRevenue = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('status', ['paid', 'fulfilled'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->sum(DB::raw('COALESCE(amount_cents, 0)'));

        $refundCount = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->where('status', 'refunded')
            ->where('updated_at', '>=', $start)
            ->where('updated_at', '<', $end)
            ->count();

        $unlockCount = (int) DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('status', 'active')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();

        $unlockRate = $paidOrders > 0
            ? round(($unlockCount / $paidOrders) * 100, 1)
            : 0;

        $webhookFailures = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where(function ($query): void {
                $query->where('signature_ok', 0)
                    ->orWhereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                    ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
            })
            ->count();

        return [
            Stat::make(__('ops.widgets.paid_orders_today'), (string) $paidOrders),
            Stat::make(__('ops.widgets.revenue_today'), (string) $todayRevenue)->description(__('ops.widgets.cents')),
            Stat::make(__('ops.widgets.unlock_rate'), (string) $unlockRate . '%'),
            Stat::make(__('ops.widgets.refund_count'), (string) $refundCount)
                ->color($refundCount > 0 ? 'warning' : 'success'),
            Stat::make(__('ops.widgets.webhook_failures'), (string) $webhookFailures)
                ->color($webhookFailures > 0 ? 'danger' : 'success'),
        ];
    }
}
