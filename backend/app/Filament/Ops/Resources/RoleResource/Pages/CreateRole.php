<?php

namespace App\Filament\Ops\Resources\RoleResource\Pages;

use App\Filament\Ops\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'role_create',
            'Role',
            (string) $record->id,
            ['name' => $record->name]
        );
    }
}
