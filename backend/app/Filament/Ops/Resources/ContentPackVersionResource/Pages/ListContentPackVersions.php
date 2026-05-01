<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPackVersionResource\Pages;

use App\Filament\Ops\Resources\ContentPackReleaseResource;
use App\Filament\Ops\Resources\ContentPackVersionResource;
use App\Filament\Ops\Support\ContentAccess;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentPackVersions extends ListRecords
{
    protected static string $resource = ContentPackVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('releaseQueue')
                ->label('Open Release Queue')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn (): bool => ContentAccess::canReleaseContentPacks())
                ->url(ContentPackReleaseResource::getUrl('index')),
            Actions\CreateAction::make(),
        ];
    }
}
