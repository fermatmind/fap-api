<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleSlugResource\Pages;

use App\Filament\Ops\Resources\ScaleSlugResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListScaleSlugs extends ListRecords
{
    protected static string $resource = ScaleSlugResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
