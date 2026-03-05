<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleCategoryResource\Pages;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListArticleCategories extends ListRecords
{
    protected static string $resource = ArticleCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
