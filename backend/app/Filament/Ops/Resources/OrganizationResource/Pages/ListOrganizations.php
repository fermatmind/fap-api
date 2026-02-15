<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrganizationResource\Pages;

use App\Filament\Ops\Resources\OrganizationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
