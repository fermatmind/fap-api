<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MediaAssetResource\Pages;

use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMediaAssets extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.actions.create_resource', ['resource' => MediaAssetResource::getModelLabel()])),
        ];
    }
}
