<?php

namespace App\Filament\Resources\DeployResource\Pages;

use App\Filament\Resources\DeployResource;
use Filament\Resources\Pages\ListRecords;
use App\Services\Audit\AuditLogger;

class ListDeployEvents extends ListRecords
{
    protected static string $resource = DeployResource::class;

    public function mount(): void
    {
        parent::mount();

        app(AuditLogger::class)->log(
            request(),
            'deploy_events_view',
            'OpsDeployEvent',
            null,
            ['page' => 'deploy_events']
        );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
