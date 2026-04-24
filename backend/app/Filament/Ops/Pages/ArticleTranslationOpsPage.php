<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Services\Cms\ArticleTranslationWorkflowException;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\Cms\SiblingTranslationWorkflowService;
use App\Services\Ops\CmsTranslationOpsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class ArticleTranslationOpsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'Editorial';

    protected static ?string $navigationLabel = 'Translation Ops';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'article-translation-ops';

    protected static string $view = 'filament.ops.pages.article-translation-ops';

    public string $contentTypeFilter = 'all';

    public string $slugSearch = '';

    public string $sourceLocaleFilter = 'all';

    public string $targetLocaleFilter = 'all';

    public string $translationStatusFilter = 'all';

    public string $staleFilter = 'all';

    public string $publishedFilter = 'all';

    public bool $missingLocaleFilter = false;

    public string $ownershipFilter = 'all';

    public ?string $selectedGroupId = null;

    /** @var array<string, int> */
    public array $metrics = [];

    /** @var list<array<string, mixed>> */
    public array $groups = [];

    /** @var list<array<string, mixed>> */
    public array $summaryCards = [];

    /** @var list<string> */
    public array $localeColumns = [];

    /** @var list<array<string, mixed>> */
    public array $coverageMatrix = [];

    /** @var array<string, mixed>|null */
    public ?array $selectedGroup = null;

    /** @var array<string, list<string>> */
    public array $filterOptions = [
        'content_types' => [],
        'locales' => [],
        'statuses' => [],
    ];

    public function mount(): void
    {
        $this->refreshDashboard();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.translation');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.article_translation_ops');
    }

    public function getTitle(): string
    {
        return __('ops.translation_ops.title');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    public function updatedContentTypeFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedSlugSearch(): void
    {
        $this->refreshDashboard();
    }

    public function updatedSourceLocaleFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedTargetLocaleFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedTranslationStatusFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedStaleFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedPublishedFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedMissingLocaleFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedOwnershipFilter(): void
    {
        $this->refreshDashboard();
    }

    public function resetFilters(): void
    {
        $this->contentTypeFilter = 'all';
        $this->slugSearch = '';
        $this->sourceLocaleFilter = 'all';
        $this->targetLocaleFilter = 'all';
        $this->translationStatusFilter = 'all';
        $this->staleFilter = 'all';
        $this->publishedFilter = 'all';
        $this->missingLocaleFilter = false;
        $this->ownershipFilter = 'all';

        $this->refreshDashboard();
    }

    public function inspectGroup(string $groupId): void
    {
        $this->selectedGroupId = $groupId;
        $this->refreshDashboard();
    }

    public function createTranslationDraft(mixed $contentTypeOrRecordId, mixed $recordIdOrTargetLocale = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.create'));
        }

        [$contentType, $recordId, $targetLocale] = $this->normalizeCreateArgs(
            $contentTypeOrRecordId,
            $recordIdOrTargetLocale,
            $targetLocale
        );
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $result = $articleWorkflow->createMachineDraft($this->article($recordId), $targetLocale, $this->adminUserId());
                $message = __('ops.translation_ops.notifications.draft_created_revision', [
                    'locale' => $result['article']->locale,
                    'revision' => $result['revision']->id,
                ]);
            } else {
                $record = $siblingWorkflow->createMachineDraft($contentType, $this->record($contentType, $recordId, $siblingWorkflow), $targetLocale);
                $message = __('ops.translation_ops.notifications.draft_created_row', [
                    'locale' => $record->locale,
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.draft_created_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function resyncFromSource(mixed $contentTypeOrRecordId, mixed $recordId = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.resync'));
        }

        [$contentType, $recordId] = $this->normalizeRecordArgs($contentTypeOrRecordId, $recordId);
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $result = $articleWorkflow->resyncFromSource($this->article($recordId), $this->adminUserId());
                $message = __('ops.translation_ops.notifications.resynced_revision', [
                    'revision' => $result['revision']->id,
                ]);
            } else {
                $record = $siblingWorkflow->resyncFromSource($contentType, $this->record($contentType, $recordId, $siblingWorkflow));
                $message = __('ops.translation_ops.notifications.resynced_row', [
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.resynced_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function promoteToHumanReview(mixed $contentTypeOrRecordId, mixed $recordId = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.review'));
        }

        [$contentType, $recordId] = $this->normalizeRecordArgs($contentTypeOrRecordId, $recordId);
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $revision = $articleWorkflow->promoteToHumanReview($this->article($recordId));
                $message = __('ops.translation_ops.notifications.promoted_revision', [
                    'revision' => $revision->id,
                ]);
            } else {
                $record = $siblingWorkflow->promoteToHumanReview($contentType, $this->record($contentType, $recordId, $siblingWorkflow));
                $message = __('ops.translation_ops.notifications.promoted_row', [
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.promoted_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function approveTranslation(mixed $contentTypeOrRecordId, mixed $recordId = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canReview()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.approve'));
        }

        [$contentType, $recordId] = $this->normalizeRecordArgs($contentTypeOrRecordId, $recordId);
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $revision = $articleWorkflow->approveTranslation($this->article($recordId));
                $message = __('ops.translation_ops.notifications.approved_revision', [
                    'revision' => $revision->id,
                ]);
            } else {
                $record = $siblingWorkflow->approveTranslation($contentType, $this->record($contentType, $recordId, $siblingWorkflow));
                $message = __('ops.translation_ops.notifications.approved_row', [
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.approved_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function publishCurrentRevision(mixed $contentTypeOrRecordId, mixed $recordId = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.publish'));
        }

        [$contentType, $recordId] = $this->normalizeRecordArgs($contentTypeOrRecordId, $recordId);
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $revision = $articleWorkflow->publishTranslation($this->article($recordId));
                $message = __('ops.translation_ops.notifications.published_revision', [
                    'revision' => $revision->id,
                ]);
            } else {
                $record = $siblingWorkflow->publishTranslation($contentType, $this->record($contentType, $recordId, $siblingWorkflow));
                $message = __('ops.translation_ops.notifications.published_row', [
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.published_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function archiveStaleRevision(mixed $contentTypeOrRecordId, mixed $recordId = null, mixed $targetLocale = ''): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.translation_ops.auth.archive'));
        }

        [$contentType, $recordId] = $this->normalizeRecordArgs($contentTypeOrRecordId, $recordId);
        $articleWorkflow = app(ArticleTranslationWorkflowService::class);
        $siblingWorkflow = app(SiblingTranslationWorkflowService::class);

        try {
            if ($contentType === 'article') {
                $revision = $articleWorkflow->archiveStaleRevision($this->article($recordId));
                $message = __('ops.translation_ops.notifications.archived_revision', [
                    'revision' => $revision->id,
                ]);
            } else {
                $record = $siblingWorkflow->archiveStale($contentType, $this->record($contentType, $recordId, $siblingWorkflow));
                $message = __('ops.translation_ops.notifications.archived_row', [
                    'record' => $record->id,
                ]);
            }

            Notification::make()->title(__('ops.translation_ops.notifications.archived_title'))->body($message)->success()->send();
        } catch (ArticleTranslationWorkflowException|CmsTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    private function refreshDashboard(): void
    {
        $dashboard = app(CmsTranslationOpsService::class)->dashboard($this->filters(), $this->selectedGroupId);

        $this->metrics = $dashboard['metrics'];
        $this->groups = $dashboard['groups'];
        $this->summaryCards = $dashboard['summary_cards'] ?? [];
        $this->localeColumns = $dashboard['locale_columns'] ?? [];
        $this->coverageMatrix = $dashboard['coverage_matrix'] ?? [];
        $this->selectedGroup = $dashboard['selected_group'];
        $this->filterOptions = $dashboard['filter_options'];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'content_type' => $this->contentTypeFilter,
            'slug' => $this->slugSearch,
            'source_locale' => $this->sourceLocaleFilter,
            'target_locale' => $this->targetLocaleFilter,
            'translation_status' => $this->translationStatusFilter,
            'stale' => $this->staleFilter,
            'published' => $this->publishedFilter,
            'missing_locale' => $this->missingLocaleFilter,
            'ownership' => $this->ownershipFilter,
        ];
    }

    private function article(int $articleId): Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'sourceCanonical.workingRevision', 'seoMeta'])
            ->findOrFail($articleId);
    }

    private function record(string $contentType, int $recordId, SiblingTranslationWorkflowService $workflow): Model
    {
        $modelClass = $workflow->adapter($contentType)->modelClass();

        return $modelClass::query()->withoutGlobalScopes()->findOrFail($recordId);
    }

    /**
     * @return array{string,int,string}
     */
    private function normalizeCreateArgs(mixed $contentTypeOrRecordId, mixed $recordIdOrTargetLocale, mixed $targetLocale): array
    {
        if (is_numeric($contentTypeOrRecordId)) {
            return ['article', (int) $contentTypeOrRecordId, (string) $recordIdOrTargetLocale];
        }

        return [
            (string) $contentTypeOrRecordId,
            (int) $recordIdOrTargetLocale,
            (string) $targetLocale,
        ];
    }

    /**
     * @return array{string,int}
     */
    private function normalizeRecordArgs(mixed $contentTypeOrRecordId, mixed $recordId): array
    {
        if (is_numeric($contentTypeOrRecordId)) {
            return ['article', (int) $contentTypeOrRecordId];
        }

        return [(string) $contentTypeOrRecordId, (int) $recordId];
    }

    private function adminUserId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();

        return is_object($actor) && is_numeric(data_get($actor, 'id'))
            ? (int) data_get($actor, 'id')
            : null;
    }

    private function notifyWorkflowFailure(\Throwable $exception): void
    {
        $body = method_exists($exception, 'blockers')
            ? implode('; ', $exception->blockers()) ?: $exception->getMessage()
            : $exception->getMessage();

        Notification::make()
            ->title(__('ops.translation_ops.notifications.blocked_title'))
            ->body($body)
            ->danger()
            ->send();
    }
}
