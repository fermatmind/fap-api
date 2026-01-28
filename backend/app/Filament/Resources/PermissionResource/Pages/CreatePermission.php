<?php

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'permission_create',
            'Permission',
            (string) $record->id,
            ['name' => $record->name]
        );
    }
}
