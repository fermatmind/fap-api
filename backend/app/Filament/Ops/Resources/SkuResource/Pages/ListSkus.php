<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SkuResource\Pages;

use App\Filament\Ops\Resources\SkuResource;
use Filament\Resources\Pages\ListRecords;

class ListSkus extends ListRecords
{
    protected static string $resource = SkuResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
