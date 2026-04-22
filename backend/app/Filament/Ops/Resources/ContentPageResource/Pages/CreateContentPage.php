<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPageResource\Pages;

use App\Filament\Ops\Resources\ContentPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentPage extends CreateRecord
{
    protected static string $resource = ContentPageResource::class;
}
