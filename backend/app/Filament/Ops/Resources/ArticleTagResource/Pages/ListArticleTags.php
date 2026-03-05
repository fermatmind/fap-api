<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleTagResource\Pages;

use App\Filament\Ops\Resources\ArticleTagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListArticleTags extends ListRecords
{
    protected static string $resource = ArticleTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
