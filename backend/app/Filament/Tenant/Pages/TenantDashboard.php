<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Widgets\TeamDynamicsOverviewWidget;
use Filament\Pages\Dashboard;

class TenantDashboard extends Dashboard
{
    protected static ?string $title = 'Tenant Console';

    public function getWidgets(): array
    {
        return [
            TeamDynamicsOverviewWidget::class,
        ];
    }
}
