<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CommerceKpiWidget extends BaseWidget
{
    protected ?string $heading = 'Today Business';

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $start = now()->startOfDay();
        $end = (clone $start)->addDay();

        if ($orgId <= 0) {
            return [
                Stat::make('Paid Orders Today', '0')->description('Select org to view live metrics'),
                Stat::make('Revenue Today', '0')->description('No organization selected'),
                Stat::make('Unlock Rate', '0%')->description('No organization selected'),
                Stat::make('Refund Count', '0')->description('No organization selected'),
                Stat::make('Webhook Failures', '0')->description('No organization selected'),
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
            Stat::make('Paid Orders Today', (string) $paidOrders),
            Stat::make('Revenue Today', (string) $todayRevenue)->description('cents'),
            Stat::make('Unlock Rate', (string) $unlockRate . '%'),
            Stat::make('Refund Count', (string) $refundCount)
                ->color($refundCount > 0 ? 'warning' : 'success'),
            Stat::make('Webhook Failures', (string) $webhookFailures)
                ->color($webhookFailures > 0 ? 'danger' : 'success'),
        ];
    }
}
