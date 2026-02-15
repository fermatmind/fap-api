<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListScaleRegistries extends ListRecords
{
    protected static string $resource = ScaleRegistryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
