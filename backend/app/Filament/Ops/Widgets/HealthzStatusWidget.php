<?php

namespace App\Filament\Ops\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthzStatusWidget extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ops.widgets.healthz_status');
    }

    protected function getStats(): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('ops_healthz_snapshots')) {
            return [
                Stat::make(__('ops.widgets.healthz'), __('ops.widgets.no_data'))->color('gray'),
            ];
        }

        $row = DB::table('ops_healthz_snapshots')->orderByDesc('created_at')->first();
        if (!$row) {
            return [
                Stat::make(__('ops.widgets.healthz'), __('ops.widgets.no_data'))->color('gray'),
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
                if (!$depOk) {
                    $failedDeps++;
                }
            }
        }

        return [
            Stat::make(__('ops.widgets.status'), $ok ? __('ops.widgets.ok') : __('ops.widgets.fail'))
                ->color($ok ? 'success' : 'danger')
                ->description((string) ($row->env ?? '')),
            Stat::make(__('ops.widgets.failed_deps'), (string) $failedDeps)
                ->color($failedDeps > 0 ? 'danger' : 'success'),
            Stat::make(__('ops.widgets.error_codes'), (string) count($errorCodes))
                ->color(count($errorCodes) > 0 ? 'warning' : 'success'),
        ];
    }
}
