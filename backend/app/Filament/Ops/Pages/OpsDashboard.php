<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Widgets\CommerceKpiWidget;
use App\Filament\Ops\Widgets\FunnelWidget;
use App\Filament\Ops\Widgets\HealthzStatusWidget;
use App\Filament\Ops\Widgets\QueueFailureWidget;
use App\Filament\Ops\Widgets\WebhookFailureWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;

class OpsDashboard extends Dashboard
{
    protected static ?string $title = 'Ops Dashboard';

    protected static ?string $slug = 'dashboard';

    protected static bool $isDiscovered = false;

    public static function getNavigationLabel(): string
    {
        return 'Dashboard';
    }

    public function getColumns(): int | string | array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            CommerceKpiWidget::class,
            WebhookFailureWidget::class,
            QueueFailureWidget::class,
            HealthzStatusWidget::class,
            FunnelWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orderLookup')
                ->label('Order Lookup')
                ->url('/ops/order-lookup'),
            Action::make('webhookFailures')
                ->label('Webhook Failures')
                ->url('/ops/webhook-monitor'),
            Action::make('failedJobs')
                ->label('Failed Jobs')
                ->url('/ops/queue-monitor'),
            Action::make('contentProbe')
                ->label('Content Probe')
                ->url('/ops/content-pack-releases'),
            Action::make('switchOrg')
                ->label('Create/Switch Org')
                ->url('/ops/select-org'),
        ];
    }
}
