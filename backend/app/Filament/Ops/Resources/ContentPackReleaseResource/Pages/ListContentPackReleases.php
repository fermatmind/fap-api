<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPackReleaseResource\Pages;

use App\Filament\Ops\Resources\ContentPackReleaseResource;
use Filament\Resources\Pages\ListRecords;

class ListContentPackReleases extends ListRecords
{
    protected static string $resource = ContentPackReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
