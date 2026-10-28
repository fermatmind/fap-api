<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Concerns\InteractsWithEntertainmentStatistics;
use App\Filament\Ops\Support\OpsMetricsAccess;
use Filament\Pages\Page;

final class EntertainmentStatisticsPage extends Page
{
    use InteractsWithEntertainmentStatistics;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'entertainment-statistics';

    protected static string $view = 'filament.ops.pages.entertainment-statistics';

    public static function canAccess(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('entertainment-activity.title');
    }

    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }
}
