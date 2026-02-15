<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QueueFailureWidget extends BaseWidget
{
    protected ?string $heading = 'Queue Failures';

    protected function getStats(): array
    {
        if (! \App\Support\SchemaBaseline::hasTable('failed_jobs')) {
            return [
                Stat::make('failed_jobs', '0'),
            ];
        }

        $count = (int) DB::table('failed_jobs')->count();

        return [
            Stat::make('failed_jobs count', (string) $count)
                ->color($count > 0 ? 'danger' : 'success'),
        ];
    }
}
