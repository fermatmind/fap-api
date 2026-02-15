<?php

namespace App\Filament\Ops\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FunnelWidget extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ops.widgets.funnel_7d');
    }

    protected function getStats(): array
    {
        $events = [
            'attempt_start' => __('ops.widgets.attempt_start'),
            'attempt_submit' => __('ops.widgets.attempt_submit'),
            'paywall_view' => __('ops.widgets.paywall_view'),
            'checkout' => __('ops.widgets.checkout'),
            'payment_success' => __('ops.widgets.paid'),
            'unlocked' => __('ops.widgets.unlocked'),
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
                Stat::make(__('ops.widgets.funnel'), __('ops.widgets.no_data'))->color('gray'),
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
