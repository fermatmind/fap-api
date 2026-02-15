<?php

namespace App\Filament\Ops\Resources\AdminUserResource\Pages;

use App\Filament\Ops\Resources\AdminUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected ?string $plainPasswordToCheck = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $rawPassword = trim((string) ($data['password'] ?? ''));
        $this->plainPasswordToCheck = $rawPassword !== '' ? $rawPassword : null;

        if ($this->plainPasswordToCheck !== null && $this->isPasswordReused($this->plainPasswordToCheck)) {
            throw ValidationException::withMessages([
                'data.password' => 'Password was used recently and cannot be reused.',
            ]);
        }

        if ($this->plainPasswordToCheck !== null) {
            $data['password_changed_at'] = now();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;
        if ($this->plainPasswordToCheck !== null && \App\Support\SchemaBaseline::hasTable('admin_user_password_histories')) {
            DB::table('admin_user_password_histories')->insert([
                'admin_user_id' => (int) $record->id,
                'password_hash' => (string) $record->password,
                'created_at' => now(),
            ]);
        }

        app(\App\Services\Audit\AuditLogger::class)->log(
            request(),
            'admin_user_update',
            'AdminUser',
            (string) $record->id,
            ['email' => $record->email]
        );
    }

    private function isPasswordReused(string $plainPassword): bool
    {
        if (!\App\Support\SchemaBaseline::hasTable('admin_user_password_histories')) {
            return false;
        }

        $historyLimit = max(1, (int) config('admin.password_policy.history_limit', 5));

        $rows = DB::table('admin_user_password_histories')
            ->where('admin_user_id', (int) $this->record->id)
            ->orderByDesc('created_at')
            ->limit($historyLimit)
            ->pluck('password_hash')
            ->all();

        foreach ($rows as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }
}
