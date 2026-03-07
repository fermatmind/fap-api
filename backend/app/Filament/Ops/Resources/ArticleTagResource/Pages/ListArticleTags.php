<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleTagResource\Pages;

use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListArticleTags extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ArticleTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
