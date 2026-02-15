<?php

namespace App\Filament\Ops\Resources\AdminUserResource\Pages;

use App\Filament\Ops\Resources\AdminUserResource;
use Illuminate\Support\Facades\DB;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminUser extends CreateRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        if (\App\Support\SchemaBaseline::hasTable('admin_user_password_histories')) {
            DB::table('admin_user_password_histories')->insert([
                'admin_user_id' => (int) $record->id,
                'password_hash' => (string) $record->password,
                'created_at' => now(),
            ]);
        }

        $record->forceFill([
            'password_changed_at' => now(),
        ])->save();

        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'admin_user_create',
            'AdminUser',
            (string) $record->id,
            ['email' => $record->email]
        );
    }
}
