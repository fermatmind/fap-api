<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Concerns\InteractsWithEntertainmentStatistics;
use App\Filament\Ops\Support\OpsMetricsAccess;
use Filament\Widgets\Widget;

final class EntertainmentStatisticsWidget extends Widget
{
    use InteractsWithEntertainmentStatistics;

    protected static bool $isLazy = false;

    protected static string $view = 'filament.ops.widgets.entertainment-statistics-widget';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    protected function defaultLookbackDays(): int
    {
        return 0;
    }
}
