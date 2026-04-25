<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SupportArticleResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\SupportArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportArticles extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = SupportArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.actions.create_resource', ['resource' => SupportArticleResource::getModelLabel()])),
        ];
    }
}
