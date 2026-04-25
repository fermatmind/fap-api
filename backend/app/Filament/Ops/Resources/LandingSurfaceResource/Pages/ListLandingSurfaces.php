<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\LandingSurfaceResource\Pages;

use App\Filament\Ops\Resources\LandingSurfaceResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLandingSurfaces extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = LandingSurfaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.actions.create_resource', ['resource' => LandingSurfaceResource::getModelLabel()])),
        ];
    }
}
