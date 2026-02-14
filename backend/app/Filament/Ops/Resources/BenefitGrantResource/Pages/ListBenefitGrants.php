<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\BenefitGrantResource\Pages;

use App\Filament\Ops\Resources\BenefitGrantResource;
use Filament\Resources\Pages\ListRecords;

class ListBenefitGrants extends ListRecords
{
    protected static string $resource = BenefitGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
