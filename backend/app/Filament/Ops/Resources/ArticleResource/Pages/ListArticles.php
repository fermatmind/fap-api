<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListArticles extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.articles.plural');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.articles.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.resources.articles.actions.create'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
