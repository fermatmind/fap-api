<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CommerceKpiWidget extends BaseWidget
{
    protected ?string $heading = 'Commerce KPIs';

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $start = now()->startOfDay();
        $end = (clone $start)->addDay();

        $paidOrders = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('status', ['paid', 'fulfilled', 'refunded'])
            ->where('updated_at', '>=', $start)
            ->where('updated_at', '<', $end)
            ->count();

        $todayRevenue = (int) DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('status', ['paid', 'fulfilled'])
            ->where('updated_at', '>=', $start)
            ->where('updated_at', '<', $end)
            ->sum(DB::raw('COALESCE(amount_total, amount_cents, 0)'));

        return [
            Stat::make('Paid Orders Today', (string) $paidOrders),
            Stat::make('Revenue Today (cents)', (string) $todayRevenue),
        ];
    }
}
