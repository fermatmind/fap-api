<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CommerceKpiWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    private const NO_ORG_PLACEHOLDER = '—';

    protected function getHeading(): ?string
    {
        return 'Commerce Overview';
    }

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $currentOrgIds = $this->currentOrgIds($orgId);
        $start = now()->startOfDay();
        $end = (clone $start)->addDay();

        if ($orgId <= 0) {
            return [
                $this->noOrgStat(__('ops.widgets.paid_orders_today'), __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat('Pending unresolved', __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat('Paid without grant', __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat('Compensated today', __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat(__('ops.widgets.refund_count'), __('ops.widgets.select_org_to_view_metrics')),
                $this->noOrgStat(__('ops.widgets.webhook_failures'), __('ops.widgets.select_org_to_view_metrics')),
            ];
        }

        $paidOrders = (int) DB::table('orders')
            ->whereIn('org_id', $currentOrgIds)
            ->where('payment_state', 'paid')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->count();

        $pendingUnresolved = (int) DB::table('orders')
            ->whereIn('org_id', $currentOrgIds)
            ->whereIn('payment_state', ['created', 'pending'])
            ->count();

        $paidNoGrant = (int) DB::table('orders')
            ->whereIn('org_id', $currentOrgIds)
            ->where('payment_state', 'paid')
            ->where('grant_state', '!=', 'granted')
            ->count();

        $compensatedRecently = (int) DB::table('orders')
            ->whereIn('org_id', $currentOrgIds)
            ->whereNotNull('last_reconciled_at')
            ->where('last_reconciled_at', '>=', $start)
            ->where('last_reconciled_at', '<', $end)
            ->count();

        $refundCount = (int) DB::table('orders')
            ->whereIn('org_id', $currentOrgIds)
            ->where('payment_state', 'refunded')
            ->where('refunded_at', '>=', $start)
            ->where('refunded_at', '<', $end)
            ->count();

        $webhookFailures = (int) DB::table('payment_events')
            ->whereIn('org_id', $currentOrgIds)
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
            Stat::make('Paid without grant', (string) $paidNoGrant)
                ->color($paidNoGrant > 0 ? 'danger' : 'success'),
            Stat::make('Compensated today', (string) $compensatedRecently)
                ->color($compensatedRecently > 0 ? 'warning' : 'gray'),
            Stat::make(__('ops.widgets.refund_count'), (string) $refundCount)
                ->color($refundCount > 0 ? 'warning' : 'success'),
            Stat::make(__('ops.widgets.webhook_failures'), (string) $webhookFailures)
                ->color($webhookFailures > 0 ? 'danger' : 'success'),
        ];
    }

    private function noOrgStat(string $label, string $description): Stat
    {
        return Stat::make($label, self::NO_ORG_PLACEHOLDER)
            ->description($description)
            ->color('gray');
    }

    /**
     * @return list<int>
     */
    private function currentOrgIds(int $orgId): array
    {
        return $orgId > 0 ? [0, $orgId] : [0];
    }
}
