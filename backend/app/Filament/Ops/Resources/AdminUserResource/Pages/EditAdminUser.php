<?php

namespace App\Filament\Ops\Resources\AdminUserResource\Pages;

use App\Filament\Ops\Resources\AdminUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->record;
        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'admin_user_update',
            'AdminUser',
            (string) $record->id,
            ['email' => $record->email]
        );
    }
}
