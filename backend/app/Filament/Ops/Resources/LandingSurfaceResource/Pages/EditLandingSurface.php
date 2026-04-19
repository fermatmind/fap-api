<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\LandingSurfaceResource\Pages;

use App\Filament\Ops\Resources\LandingSurfaceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLandingSurface extends EditRecord
{
    protected static string $resource = LandingSurfaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(false),
        ];
    }
}
