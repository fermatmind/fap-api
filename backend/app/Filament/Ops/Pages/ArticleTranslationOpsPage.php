<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
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

    public function promoteToHumanReview(int $articleId): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to update translation review state.');
        }

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->findOrFail($articleId);
        $revision = $article->workingRevision;

        if (! $revision instanceof ArticleTranslationRevision) {
            throw new AuthorizationException('This translation does not have a working revision.');
        }

        if ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_MACHINE_DRAFT) {
            throw new AuthorizationException('Only machine_draft translations can be promoted to human review here.');
        }

        $revision->forceFill([
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'reviewed_at' => $revision->reviewed_at ?? now(),
        ])->save();

        $article->forceFill([
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
        ])->save();

        Notification::make()
            ->title('Translation promoted')
            ->body('The working revision is now in human_review.')
            ->success()
            ->send();

        $this->refreshDashboard();
    }

    public function publishCurrentRevision(int $articleId): void
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->findOrFail($articleId);

        ArticleResource::releaseRecord($article, 'translation_ops_console');

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
}
