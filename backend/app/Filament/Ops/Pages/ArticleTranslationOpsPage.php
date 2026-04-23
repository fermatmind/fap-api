<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Services\Cms\ArticleTranslationWorkflowException;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Ops\ArticleTranslationOpsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;

class ArticleTranslationOpsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'Editorial';

    protected static ?string $navigationLabel = 'Translation Ops';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'article-translation-ops';

    protected static string $view = 'filament.ops.pages.article-translation-ops';

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

    /** @var array<string, mixed>|null */
    public ?array $selectedGroup = null;

    /** @var array<string, list<string>> */
    public array $filterOptions = [
        'locales' => [],
        'statuses' => [],
    ];

    public function mount(): void
    {
        $this->refreshDashboard();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.editorial');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.article_translation_ops');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
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

    public function createTranslationDraft(
        int $sourceArticleId,
        string $targetLocale,
        ArticleTranslationWorkflowService $workflow
    ): void {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to create translation drafts.');
        }

        try {
            $result = $workflow->createMachineDraft(
                $this->article($sourceArticleId),
                $targetLocale,
                $this->adminUserId(),
            );

            Notification::make()
                ->title('Translation draft created')
                ->body('Created '.$result['article']->locale.' machine_draft revision #'.$result['revision']->id.'.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function resyncFromSource(int $articleId, ArticleTranslationWorkflowService $workflow): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to re-sync translation drafts.');
        }

        try {
            $result = $workflow->resyncFromSource($this->article($articleId), $this->adminUserId());

            Notification::make()
                ->title('Translation re-synced')
                ->body('Created new machine_draft revision #'.$result['revision']->id.' under the existing target article.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function promoteToHumanReview(int $articleId, ArticleTranslationWorkflowService $workflow): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to update translation review state.');
        }

        try {
            $revision = $workflow->promoteToHumanReview($this->article($articleId));

            Notification::make()
                ->title('Translation promoted')
                ->body('Working revision #'.$revision->id.' is now in human_review.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function approveTranslation(int $articleId, ArticleTranslationWorkflowService $workflow): void
    {
        if (! ContentAccess::canReview()) {
            throw new AuthorizationException('You do not have permission to approve translation revisions.');
        }

        try {
            $revision = $workflow->approveTranslation($this->article($articleId));

            Notification::make()
                ->title('Translation approved')
                ->body('Working revision #'.$revision->id.' passed preflight and is approved.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function publishCurrentRevision(int $articleId, ArticleTranslationWorkflowService $workflow): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to publish translation revisions.');
        }

        try {
            $revision = $workflow->publishTranslation($this->article($articleId));

            Notification::make()
                ->title('Translation published')
                ->body('Published revision #'.$revision->id.' through translation preflight.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    public function archiveStaleRevision(int $articleId, ArticleTranslationWorkflowService $workflow): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to archive translation revisions.');
        }

        try {
            $revision = $workflow->archiveStaleRevision($this->article($articleId));

            Notification::make()
                ->title('Stale revision archived')
                ->body('Archived stale working revision #'.$revision->id.'.')
                ->success()
                ->send();
        } catch (ArticleTranslationWorkflowException $exception) {
            $this->notifyWorkflowFailure($exception);
        }

        $this->refreshDashboard();
    }

    private function refreshDashboard(): void
    {
        $dashboard = app(ArticleTranslationOpsService::class)->dashboard($this->filters(), $this->selectedGroupId);

        $this->metrics = $dashboard['metrics'];
        $this->groups = $dashboard['groups'];
        $this->selectedGroup = $dashboard['selected_group'];
        $this->filterOptions = $dashboard['filter_options'];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
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

    private function adminUserId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();

        return is_object($actor) && is_numeric(data_get($actor, 'id'))
            ? (int) data_get($actor, 'id')
            : null;
    }

    private function notifyWorkflowFailure(ArticleTranslationWorkflowException $exception): void
    {
        Notification::make()
            ->title('Translation action blocked')
            ->body(implode('; ', $exception->blockers) ?: $exception->getMessage())
            ->danger()
            ->send();
    }
}
