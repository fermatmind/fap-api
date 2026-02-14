<?php

namespace App\Filament\Ops\Resources\ContentReleaseResource\Pages;

use App\Filament\Ops\Resources\ContentReleaseResource;
use Filament\Resources\Pages\ListRecords;

class ListContentReleases extends ListRecords
{
    protected static string $resource = ContentReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
