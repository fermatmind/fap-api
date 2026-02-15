<?php

namespace App\Filament\Ops\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FunnelWidget extends BaseWidget
{
    protected ?string $heading = 'Funnel (7d)';

    protected function getStats(): array
    {
        $events = [
            'attempt_start' => 'Attempt Start',
            'attempt_submit' => 'Attempt Submit',
            'paywall_view' => 'Paywall View',
            'checkout' => 'Checkout',
            'payment_success' => 'Paid',
            'unlocked' => 'Unlocked',
        ];

        $from = now()->subDays(7)->toDateString();

        $rows = collect();
        if (\App\Support\SchemaBaseline::hasTable('v_funnel_daily')) {
            $rows = DB::table('v_funnel_daily')
                ->where('day', '>=', $from)
                ->whereIn('event_name', array_keys($events))
                ->select('event_name', DB::raw('SUM(events_count) as total'))
                ->groupBy('event_name')
                ->get();
        } elseif (\App\Support\SchemaBaseline::hasTable('events')) {
            $rows = DB::table('events')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->whereIn('event_name', array_keys($events))
                ->select('event_name', DB::raw('COUNT(*) as total'))
                ->groupBy('event_name')
                ->get();
        }

        if ($rows->isEmpty()) {
            return [
                Stat::make('Funnel', 'no data')->color('gray'),
            ];
        }

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->event_name] = (int) ($row->total ?? 0);
        }

        $stats = [];
        foreach ($events as $key => $label) {
            $stats[] = Stat::make($label, (string) ($totals[$key] ?? 0));
        }

        return $stats;
    }
}
