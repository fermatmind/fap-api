<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.articles.create_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.articles.create_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToArticles')
                ->label(__('ops.resources.articles.actions.all'))
                ->url(ArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('ops.resources.articles.actions.create'))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label(__('ops.resources.articles.actions.create_another'))
            ->icon('heroicon-o-document-duplicate');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.resources.articles.actions.back_to_list'))
            ->icon('heroicon-o-arrow-left');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('ops.resources.articles.notifications.created');
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
