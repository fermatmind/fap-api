<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPageResource\Pages;

use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentPages extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ContentPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
