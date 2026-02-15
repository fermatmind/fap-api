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
    protected static ?string $title = null;

    protected static ?string $slug = 'dashboard';

    protected static bool $isDiscovered = false;

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.dashboard');
    }

    public function getTitle(): string
    {
        return __('ops.dashboard.title');
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
                ->label(__('ops.dashboard.actions.order_lookup'))
                ->url('/ops/order-lookup'),
            Action::make('webhookFailures')
                ->label(__('ops.dashboard.actions.webhook_failures'))
                ->url('/ops/webhook-monitor'),
            Action::make('failedJobs')
                ->label(__('ops.dashboard.actions.failed_jobs'))
                ->url('/ops/queue-monitor'),
            Action::make('contentProbe')
                ->label(__('ops.dashboard.actions.content_probe'))
                ->url('/ops/content-pack-releases'),
            Action::make('switchOrg')
                ->label(__('ops.dashboard.actions.switch_org'))
                ->url('/ops/select-org'),
        ];
    }
}
