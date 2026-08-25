<?php

namespace App\Filament\Ops\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class HealthzStatusWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('ops.widgets.service_health_snapshot');
    }

    protected function getStats(): array
    {
        if (! \App\Support\SchemaBaseline::hasTable('ops_healthz_snapshots')) {
            return [
                Stat::make(__('ops.widgets.healthz'), __('ops.widgets.no_data'))
                    ->description(__('ops.widgets.no_health_snapshot'))
                    ->color('gray'),
            ];
        }

        $row = DB::table('ops_healthz_snapshots')->orderByDesc('created_at')->first();
        if (! $row) {
            return [
                Stat::make(__('ops.widgets.healthz'), __('ops.widgets.no_data'))
                    ->description(__('ops.widgets.no_health_snapshot'))
                    ->color('gray'),
            ];
        }

        $ok = (int) ($row->ok ?? 0) === 1;
        $deps = (array) (json_decode((string) ($row->deps_json ?? '[]'), true) ?? []);
        $errorsRaw = (array) (json_decode((string) ($row->error_codes_json ?? '[]'), true) ?? []);

        $errorCodes = [];
        if (isset($errorsRaw['codes']) && is_array($errorsRaw['codes'])) {
            $errorCodes = $errorsRaw['codes'];
        }

        $failedDeps = 0;
        foreach ($deps as $dep) {
            if (is_array($dep)) {
                $depOk = (bool) ($dep['ok'] ?? true);
                if (! $depOk) {
                    $failedDeps++;
                }
            }
        }

        return [
            Stat::make('', $ok ? __('ops.widgets.ok') : __('ops.widgets.fail'))
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($ok ? 'success' : 'danger'),
            Stat::make(__('ops.widgets.failed_deps'), (string) $failedDeps)
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color($failedDeps > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.error_codes'), (string) count($errorCodes))
                ->extraAttributes(['class' => 'ops-stat-centered'])
                ->color(count($errorCodes) > 0 ? 'warning' : 'success'),
        ];
    }
}
