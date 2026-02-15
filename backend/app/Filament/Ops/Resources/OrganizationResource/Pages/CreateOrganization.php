<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrganizationResource\Pages;

use App\Filament\Ops\Resources\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_user_id'] = (int) ($data['owner_user_id'] ?? 0);
        $data['status'] = trim((string) ($data['status'] ?? 'active')) ?: 'active';
        $data['timezone'] = trim((string) ($data['timezone'] ?? 'UTC')) ?: 'UTC';
        $data['locale'] = trim((string) ($data['locale'] ?? 'en-US')) ?: 'en-US';
        $domain = isset($data['domain']) ? trim((string) $data['domain']) : '';
        $data['domain'] = $domain !== '' ? $domain : null;

        return $data;
    }
}
