<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPackVersionResource\Pages;

use App\Filament\Ops\Resources\ContentPackVersionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentPackVersion extends CreateRecord
{
    protected static string $resource = ContentPackVersionResource::class;
}
