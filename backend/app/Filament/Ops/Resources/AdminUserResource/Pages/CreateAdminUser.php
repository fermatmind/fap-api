<?php

namespace App\Filament\Ops\Resources\AdminUserResource\Pages;

use App\Filament\Ops\Resources\AdminUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminUser extends CreateRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'admin_user_create',
            'AdminUser',
            (string) $record->id,
            ['email' => $record->email]
        );
    }
}
