<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditArticle extends EditRecord
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
        $record = $this->getRecord();
        $title = $record instanceof Article ? $record->workingRevision?->title ?? $record->title : null;

        return filled($title) ? (string) $title : __('ops.resources.articles.edit_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.articles.edit_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToArticles')
                ->label(__('ops.resources.articles.actions.all'))
                ->url(ArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Action::make('openPublicUrl')
                ->label(__('ops.resources.articles.actions.open_public_url'))
                ->url(fn (): ?string => ArticleWorkspace::publicUrl(
                    (string) $this->getRecord()->slug,
                    (string) $this->getRecord()->locale
                ), shouldOpenInNewTab: true)
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()->is_public && filled(ArticleWorkspace::publicUrl(
                    (string) $this->getRecord()->slug,
                    (string) $this->getRecord()->locale
                ))),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('ops.resources.articles.actions.save'))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.resources.articles.actions.back_to_list'))
            ->icon('heroicon-o-arrow-left');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('ops.resources.articles.notifications.updated');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Article $record */
        $record = $this->getRecord();
        $record->loadMissing(['workingRevision', 'publishedRevision', 'seoMeta', 'sourceCanonical.workingRevision']);

        $revisionState = app(ArticleTranslationRevisionWorkspace::class)->revisionFormState($record);

        return array_merge($data, $revisionState, [
            'canonical_url' => $record->seoMeta?->canonical_url,
            'og_title' => $record->seoMeta?->og_title,
            'og_description' => $record->seoMeta?->og_description,
            'og_image_url' => $record->seoMeta?->og_image_url,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Article $record */
        $record = $this->getRecord();
        $data['org_id'] = (int) $record->org_id;
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
            $data['status'],
            $data['is_public'],
            $data['published_at'],
            $data['scheduled_at'],
            $data['published_revision_id'],
            $data['title'],
            $data['excerpt'],
            $data['content_md'],
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

    protected function afterSave(): void
    {
        /** @var Article $record */
        $record = $this->getRecord();

        app(ArticleTranslationRevisionWorkspace::class)->saveWorkingRevision(
            $record,
            $this->workingRevisionPayload,
            (int) (auth((string) config('admin.guard', 'admin'))->id() ?: 0) ?: null
        );
        $this->saveSeoCompatibilityFields($record);

        $record->refresh()->loadMissing(['workingRevision', 'publishedRevision', 'sourceCanonical.workingRevision']);
    }

    private function saveSeoCompatibilityFields(Article $record): void
    {
        $hasCompatibilityValues = array_filter($this->seoCompatibilityPayload, static fn (mixed $value): bool => filled($value)) !== [];
        if (! $hasCompatibilityValues && ! $record->seoMeta instanceof ArticleSeoMeta) {
            return;
        }

        ArticleSeoMeta::query()->updateOrCreate(
            [
                'org_id' => (int) $record->org_id,
                'article_id' => (int) $record->id,
                'locale' => (string) $record->locale,
            ],
            array_merge($this->seoCompatibilityPayload, [
                'is_indexable' => (bool) $record->is_indexable,
            ])
        );
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
