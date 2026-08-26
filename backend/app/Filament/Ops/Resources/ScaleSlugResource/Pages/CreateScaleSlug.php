<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleSlugResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Filament\Ops\Resources\ScaleSlugResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScaleSlug extends CreateRecord
{
    protected static string $resource = ScaleSlugResource::class;

    public function mount(): void
    {
        $this->redirect(ScaleRegistryResource::getUrl(), navigate: true);
    }
}
