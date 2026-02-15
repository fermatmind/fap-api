<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPackVersionResource\Pages;

use App\Filament\Ops\Resources\ContentPackVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentPackVersions extends ListRecords
{
    protected static string $resource = ContentPackVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
