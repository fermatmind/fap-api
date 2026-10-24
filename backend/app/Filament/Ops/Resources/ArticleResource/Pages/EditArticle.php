<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleSeoMetaWorkspace;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Filament\Ops\Support\ArticleControlledPublicationWorkspace;
use App\Filament\Ops\Support\ArticleEditorialPackageWorkspace;
use App\Filament\Ops\Support\ArticleExistingPackageWorkspace;
use App\Filament\Ops\Support\ArticleHreflangWorkspace;
use App\Filament\Ops\Support\ArticleManualSourceReviewWorkspace;
use App\Filament\Ops\Support\ArticlePublishedFaqWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

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

    public function getHeader(): ?\Illuminate\Contracts\View\View
    {
        /** @var Article|null $record */
        $record = $this->getRecord();
        $status = $record instanceof Article ? (string) $record->status : 'draft';
        $isPublic = $record instanceof Article ? (bool) $record->is_public : false;
        $scheduledAt = $record instanceof Article ? $record->scheduled_at : null;

        $steps = [
            'draft' => __('ops.status.draft'),
            'review' => __('ops.status.review'),
            'scheduled' => __('ops.status.scheduled'),
            'published' => __('ops.status.published'),
        ];

        $currentStep = match (true) {
            $status === 'published' && $isPublic => 'published',
            $status === 'published' && $scheduledAt !== null => 'scheduled',
            $status === 'published' => 'published',
            $isPublic => 'review',
            $scheduledAt !== null => 'scheduled',
            default => 'draft',
        };

        $order = array_keys($steps);
        $activeIndex = array_search($currentStep, $order, true);

        return view('filament.ops.articles.partials.editor-status-bar', [
            'actions' => $this->getCachedHeaderActions(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'steps' => $steps,
            'currentStep' => $currentStep,
            'activeIndex' => $activeIndex,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToArticles')
                ->label(__('ops.resources.articles.actions.all'))
                ->url(ArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Action::make('seoContentWorkspace')
                ->label(__('ops.custom_pages.seo_operations.article_workspace.title'))
                ->url(fn (): string => \App\Filament\Ops\Pages\SeoOperationsPage::getUrl(['workspace' => 'content', 'article' => $this->getRecord()->id]))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray'),
            Action::make('previewDraft')
                ->label('Preview draft')
                ->url(fn (): ?string => ArticleWorkspace::previewUrl($this->getRecord(), $this->getRecord()->working_revision_id), shouldOpenInNewTab: true)
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn (): bool => filled(ArticleWorkspace::previewUrl($this->getRecord(), $this->getRecord()->working_revision_id))),
            Action::make('importEditorialPackage')
                ->label(__('ops.resources.articles.editorial_import.action'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => ContentAccess::canWrite() && ArticleEditorialPackageWorkspace::isPrivateDraft($this->getRecord()) && $this->getRecord()->working_revision_id !== null)
                ->modalDescription(__('ops.resources.articles.editorial_import.description'))
                ->modalSubmitActionLabel(__('ops.resources.articles.editorial_import.action'))
                ->form([
                    Textarea::make('target_snapshot')
                        ->label(__('ops.resources.articles.editorial_import.baseline'))
                        ->default(fn (): string => json_encode(app(ArticleEditorialPackageWorkspace::class)->baseline($this->getRecord()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                        ->helperText(__('ops.resources.articles.editorial_import.baseline_helper'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->rows(8),
                    Textarea::make('package_json')
                        ->label(__('ops.resources.articles.editorial_import.input'))
                        ->helperText(__('ops.resources.articles.editorial_import.helper'))
                        ->required()
                        ->maxLength(ArticleEditorialPackageWorkspace::MAX_INPUT_BYTES)
                        ->rows(12),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleEditorialPackageWorkspace::class)->import($this->getRecord(), (string) $data['package_json']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.package_json' => $exception->errors()['package_json'] ?? [$exception->getMessage()],
                        ]);
                    }
                    Notification::make()
                        ->title(__('ops.resources.articles.editorial_import.success'))
                        ->body(__('ops.resources.articles.editorial_import.result', ['revision' => $result['working_revision_id'], 'status' => $result['working_revision_status']]))
                        ->success()->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('importExistingPackage')
                ->label(__('ops.resources.articles.package_import.action'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => ContentAccess::canWrite()
                    && (int) $this->getRecord()->org_id === 0
                    && $this->getRecord()->published_revision_id !== null)
                ->modalDescription(__('ops.resources.articles.package_import.description'))
                ->modalSubmitActionLabel(__('ops.resources.articles.package_import.action'))
                ->form([
                    Textarea::make('target_snapshot')
                        ->label(__('ops.resources.articles.controlled_publish.snapshot'))
                        ->default(fn (): string => \App\Support\ArticleSourceTargetSnapshot::publicationHash($this->getRecord()))
                        ->helperText(__('ops.resources.articles.package_import.baseline_helper'))
                        ->readOnly()->dehydrated(false)->rows(2),
                    Textarea::make('package_json')
                        ->label(__('ops.resources.articles.package_import.input'))
                        ->helperText(__('ops.resources.articles.package_import.helper'))
                        ->required()
                        ->maxLength(ArticleExistingPackageWorkspace::MAX_INPUT_BYTES)
                        ->rows(12),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleExistingPackageWorkspace::class)->import($this->getRecord(), (string) $data['package_json']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.package_json' => $exception->errors()['package_json'] ?? [$exception->getMessage()],
                        ]);
                    }
                    Notification::make()
                        ->title(__('ops.resources.articles.package_import.success'))
                        ->body(__('ops.resources.articles.package_import.result', [
                            'revision' => $result['working_revision_id'],
                            'published' => $result['published_revision_id'],
                        ]))
                        ->success()
                        ->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            // @review-surface article_translation_revision
            Action::make('confirmManualSourceReview')
                ->label('确认人工译文源版本')
                ->icon('heroicon-o-language')
                ->visible(fn (): bool => ContentAccess::canWrite() && ! $this->getRecord()->isSourceArticle()
                    && $this->getRecord()->workingRevision?->revision_status === \App\Models\ArticleTranslationRevision::STATUS_HUMAN_REVIEW)
                ->modalDescription('核对两份完整预览后，将当前未批准人工译文绑定到当前已发布源文。正文、发布指针和导入记录保持不变，随后仍须编辑审核和原生发布校验。')
                ->fillForm(fn (): array => app(ArticleManualSourceReviewWorkspace::class)->prepare($this->getRecord()))
                ->form([
                    Textarea::make('source_hash')->label('源文状态')->readOnly()->required()->rows(2),
                    Textarea::make('target_hash')->label('译文状态')->readOnly()->required()->rows(2),
                    Textarea::make('review_context')->label('待核对版本与预览')->readOnly()->dehydrated(false)->rows(8),
                    Checkbox::make('source_reviewed')->label('已核对这两份完整预览，人工译文与当前源文相符')->accepted(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ArticleManualSourceReviewWorkspace::class)->confirm($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.source_reviewed' => $exception->errors()['source_reviewed'] ?? [$exception->getMessage()],
                        ]);
                    }
                    Notification::make()->title('人工译文源版本已确认；仍需编辑审核')->success()->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            // @review-surface article
            Action::make('publishControlled')
                ->label(__('ops.resources.articles.controlled_publish.action'))
                ->icon('heroicon-o-check-badge')
                ->visible(fn (): bool => ContentAccess::canRelease() && (int) $this->getRecord()->org_id === 0)
                ->modalDescription(__('ops.resources.articles.controlled_publish.description'))
                ->fillForm(fn (): array => app(ArticleControlledPublicationWorkspace::class)->prepare($this->getRecord()))
                ->form([
                    Textarea::make('target_hash')->label(__('ops.resources.articles.controlled_publish.snapshot'))->readOnly()->required()->rows(2),
                    Textarea::make('preflight')->label(__('ops.resources.articles.controlled_publish.preflight'))->readOnly()->dehydrated(false)->rows(12),
                    Checkbox::make('preview_approved')->label(__('ops.resources.articles.controlled_publish.preview'))->accepted(),
                    Checkbox::make('acknowledge_claims')->label(__('ops.resources.articles.controlled_publish.claims')),
                    Checkbox::make('make_indexable')->label(__('ops.resources.articles.controlled_publish.indexable'))
                        ->visible(fn (): bool => $this->getRecord()->published_revision_id === null),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleControlledPublicationWorkspace::class)->publish($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.preview_approved' => $exception->errors()['preview_approved'] ?? [$exception->getMessage()],
                        ]);
                    }
                    $notification = Notification::make();
                    if ($result['ok'] ?? false) {
                        $notification->title(__('ops.resources.articles.controlled_publish.success'))->success();
                    } else {
                        $notification->title(__('ops.resources.articles.controlled_publish.cache_failed'))->warning()->persistent();
                    }
                    $notification->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            // @review-surface article
            Action::make('releasePublishedSeo')
                ->label(__('ops.resources.articles.seo_release.action'))
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => ContentAccess::canRelease() && (int) $this->getRecord()->org_id === 0
                    && $this->getRecord()->published_revision_id !== null
                    && (! $this->getRecord()->sitemap_eligible || ! $this->getRecord()->llms_eligible))
                ->modalDescription(__('ops.resources.articles.seo_release.description'))
                ->fillForm(fn (): array => app(ArticleControlledPublicationWorkspace::class)->prepareSeoRelease($this->getRecord()))
                ->form([
                    Textarea::make('target_hash')->label(__('ops.resources.articles.controlled_publish.snapshot'))->readOnly()->required()->rows(2),
                    Textarea::make('preflight')->label(__('ops.resources.articles.seo_release.preflight'))->readOnly()->dehydrated(false)->rows(14),
                    Checkbox::make('public_page_reviewed')->label(__('ops.resources.articles.seo_release.reviewed'))->accepted(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleControlledPublicationWorkspace::class)->releaseSeo($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.public_page_reviewed' => $exception->errors()['preview_approved'] ?? [$exception->getMessage()],
                        ]);
                    }
                    $notification = Notification::make();
                    if ($result['ok']) {
                        $notification->title(__('ops.resources.articles.seo_release.success'))->success();
                    } else {
                        $notification->title(__('ops.resources.articles.seo_release.cache_failed'))->warning()->persistent();
                    }
                    $notification->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('updatePublishedCoverAlt')
                ->label(__('ops.resources.articles.cover_alt.action'))
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => ContentAccess::canRelease() && (int) $this->getRecord()->org_id === 0 && $this->getRecord()->published_revision_id !== null)
                ->modalDescription(__('ops.resources.articles.cover_alt.description'))
                ->fillForm(fn (): array => app(ArticleControlledPublicationWorkspace::class)->prepareCoverAlt($this->getRecord()))
                ->form([
                    Textarea::make('target_hash')->label(__('ops.resources.articles.controlled_publish.snapshot'))->readOnly()->required()->rows(2),
                    Textarea::make('cover_image_alt')->label(__('ops.resources.articles.fields.cover_image_alt'))->required()->maxLength(255)->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleControlledPublicationWorkspace::class)->updateCoverAlt($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.cover_image_alt' => $exception->errors()['cover_image_alt'] ?? [$exception->getMessage()],
                        ]);
                    }
                    $notification = Notification::make();
                    if ($result['ok']) {
                        $notification->title(__('ops.resources.articles.cover_alt.success'))->success();
                    } else {
                        $notification->title(__('ops.resources.articles.cover_alt.cache_failed'))->warning()->persistent();
                    }
                    $notification->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('releaseHreflangPair')
                ->label(__('ops.resources.articles.hreflang_release.action'))
                ->icon('heroicon-o-language')
                ->color('gray')
                ->visible(fn (): bool => app(ArticleHreflangWorkspace::class)->available($this->getRecord()))
                ->modalDescription(__('ops.resources.articles.hreflang_release.description'))
                ->fillForm(fn (): array => app(ArticleHreflangWorkspace::class)->prepare($this->getRecord()))
                ->form([
                    Textarea::make('target_hash')->label(__('ops.resources.articles.controlled_publish.snapshot'))->readOnly()->required()->rows(2),
                    Textarea::make('preflight')->label(__('ops.resources.articles.controlled_publish.preflight'))->readOnly()->rows(12),
                    Checkbox::make('pair_reviewed')->label(__('ops.resources.articles.hreflang_release.reviewed'))->accepted()->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticleHreflangWorkspace::class)->release($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.pair_reviewed' => $exception->errors()['pair_reviewed'] ?? [$exception->getMessage()],
                        ]);
                    }
                    $notification = Notification::make();
                    if ($result['ok']) {
                        $notification->title(__('ops.resources.articles.hreflang_release.success'))->success();
                    } else {
                        $notification->title(__('ops.resources.articles.hreflang_release.cache_failed'))->warning()->persistent();
                    }
                    $notification->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('updatePublishedFaq')
                ->label(__('ops.resources.articles.published_faq.action'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->visible(fn (): bool => ArticlePublishedFaqWorkspace::canUpdate() && (int) $this->getRecord()->org_id === 0
                    && $this->getRecord()->published_revision_id !== null
                    && $this->getRecord()->working_revision_id === $this->getRecord()->published_revision_id)
                ->modalDescription(__('ops.resources.articles.published_faq.description'))
                ->fillForm(fn (): array => app(ArticlePublishedFaqWorkspace::class)->prepare($this->getRecord()))
                ->form([
                    Textarea::make('target_hash')->label(__('ops.resources.articles.controlled_publish.snapshot'))->readOnly()->required()->rows(2),
                    Repeater::make('faq_items')->label(__('ops.resources.articles.published_faq.items'))
                        ->schema([
                            Textarea::make('question')->label(__('ops.resources.articles.published_faq.question'))->readOnly()->required()->rows(2),
                            Textarea::make('answer')->label(__('ops.resources.articles.published_faq.answer'))->required()->maxLength(10000)->rows(5),
                        ])->addable(false)->deletable(false)->reorderable(false)->minItems(1)->maxItems(6),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ArticlePublishedFaqWorkspace::class)->update($this->getRecord(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.faq_items' => $exception->errors()['faq_items'] ?? [$exception->getMessage()],
                        ]);
                    }
                    $notification = Notification::make();
                    if ($result['ok']) {
                        $notification->title(__('ops.resources.articles.published_faq.success'))->success();
                    } else {
                        $notification->title(__('ops.resources.articles.published_faq.cache_failed'))->warning()->persistent();
                    }
                    $notification->send();
                    $this->redirect(ArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
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
            'seo_title' => $record->seoMeta?->seo_title ?? $revisionState['seo_title'] ?? null,
            'seo_description' => $record->seoMeta?->seo_description ?? $revisionState['seo_description'] ?? null,
            'canonical_url' => $record->seoMeta?->canonical_url,
            'robots' => $record->seoMeta?->robots,
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
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'canonical_url' => $data['canonical_url'] ?? null,
            ...array_intersect_key($data, ['robots' => true]),
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
            $data['robots'],
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
        app(ArticleSeoMetaWorkspace::class)->save($record, $this->seoCompatibilityPayload);
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
