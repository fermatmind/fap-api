<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleSlugResource\Pages;

use App\Filament\Ops\Resources\ScaleSlugResource;
use Filament\Resources\Pages\ListRecords;

class ListScaleSlugs extends ListRecords
{
    protected static string $resource = ScaleSlugResource::class;
}
