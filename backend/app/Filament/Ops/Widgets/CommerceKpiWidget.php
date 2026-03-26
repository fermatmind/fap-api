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
                Stat::make('Pending unresolved', '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make('Paid no grant', '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make('Compensated recently', '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make(__('ops.widgets.refund_count'), '0')->description(__('ops.widgets.no_org_selected')),
                Stat::make(__('ops.widgets.webhook_failures'), '0')->description(__('ops.widgets.no_org_selected')),
            ];
        }

        $paidOrders = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->where('payment_state', 'paid')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->count();

        $pendingUnresolved = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('payment_state', ['created', 'pending'])
            ->count();

        $paidNoGrant = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->where('payment_state', 'paid')
            ->where('grant_state', '!=', 'granted')
            ->count();

        $compensatedRecently = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereNotNull('last_reconciled_at')
            ->where('last_reconciled_at', '>=', $start)
            ->where('last_reconciled_at', '<', $end)
            ->count();

        $refundCount = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->where('payment_state', 'refunded')
            ->where('refunded_at', '>=', $start)
            ->where('refunded_at', '<', $end)
            ->count();

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
            Stat::make('Pending unresolved', (string) $pendingUnresolved)
                ->color($pendingUnresolved > 0 ? 'warning' : 'success'),
            Stat::make('Paid no grant', (string) $paidNoGrant)
                ->color($paidNoGrant > 0 ? 'danger' : 'success'),
            Stat::make('Compensated recently', (string) $compensatedRecently)
                ->color($compensatedRecently > 0 ? 'warning' : 'gray'),
            Stat::make(__('ops.widgets.refund_count'), (string) $refundCount)
                ->color($refundCount > 0 ? 'warning' : 'success'),
            Stat::make(__('ops.widgets.webhook_failures'), (string) $webhookFailures)
                ->color($webhookFailures > 0 ? 'danger' : 'success'),
        ];
    }
}
