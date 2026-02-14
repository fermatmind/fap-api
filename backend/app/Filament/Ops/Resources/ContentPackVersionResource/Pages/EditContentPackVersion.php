<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPackVersionResource\Pages;

use App\Filament\Ops\Resources\ContentPackVersionResource;
use Filament\Resources\Pages\EditRecord;

class EditContentPackVersion extends EditRecord
{
    protected static string $resource = ContentPackVersionResource::class;
}
