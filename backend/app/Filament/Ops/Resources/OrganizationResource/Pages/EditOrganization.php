<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrganizationResource\Pages;

use App\Filament\Ops\Resources\OrganizationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['status'] = trim((string) ($data['status'] ?? 'active')) ?: 'active';
        $data['timezone'] = trim((string) ($data['timezone'] ?? 'UTC')) ?: 'UTC';
        $data['locale'] = trim((string) ($data['locale'] ?? 'en-US')) ?: 'en-US';
        $domain = isset($data['domain']) ? trim((string) $data['domain']) : '';
        $data['domain'] = $domain !== '' ? $domain : null;

        return $data;
    }
}
