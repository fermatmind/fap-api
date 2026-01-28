<?php

namespace App\Filament\Resources\ContentReleaseResource\Pages;

use App\Filament\Resources\ContentReleaseResource;
use Filament\Resources\Pages\ListRecords;

class ListContentReleases extends ListRecords
{
    protected static string $resource = ContentReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
