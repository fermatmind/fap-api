<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleSlugResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Filament\Ops\Resources\ScaleSlugResource;
use Filament\Resources\Pages\EditRecord;

class EditScaleSlug extends EditRecord
{
    protected static string $resource = ScaleSlugResource::class;

    public function mount(int|string $record): void
    {
        $this->redirect(ScaleRegistryResource::getUrl(), navigate: true);
    }
}
