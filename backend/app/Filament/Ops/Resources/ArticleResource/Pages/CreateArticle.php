<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @var array<string, mixed>
     */
    private array $workingRevisionPayload = [];

    /**
     * @var array<string, mixed>
     */
    private array $seoCompatibilityPayload = [];

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workingRevisionPayload = [
            'title' => $data['title'] ?? null,
            'excerpt' => $data['excerpt'] ?? null,
            'content_md' => $data['content_md'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'working_revision_status' => $data['working_revision_status'] ?? null,
        ];
        $this->seoCompatibilityPayload = [
            'canonical_url' => $data['canonical_url'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image_url' => $data['og_image_url'] ?? null,
        ];

        unset(
            $data['seo_title'],
            $data['seo_description'],
            $data['working_revision_status'],
            $data['canonical_url'],
            $data['og_title'],
            $data['og_description'],
            $data['og_image_url']
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Article $record */
        $record = $this->getRecord();

        app(ArticleTranslationRevisionWorkspace::class)->saveWorkingRevision(
            $record,
            $this->workingRevisionPayload,
            (int) (auth((string) config('admin.guard', 'admin'))->id() ?: 0) ?: null
        );
        $this->saveSeoCompatibilityFields($record);
    }

    private function saveSeoCompatibilityFields(Article $record): void
    {
        $hasCompatibilityValues = array_filter($this->seoCompatibilityPayload, static fn (mixed $value): bool => filled($value)) !== [];
        if (! $hasCompatibilityValues && ! $record->seoMeta instanceof ArticleSeoMeta) {
            return;
        }

        ArticleSeoMeta::query()->updateOrCreate(
            [
                'article_id' => (int) $record->id,
                'locale' => (string) $record->locale,
            ],
            array_merge($this->seoCompatibilityPayload, [
                'org_id' => (int) $record->org_id,
                'is_indexable' => (bool) $record->is_indexable,
            ])
        );
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
