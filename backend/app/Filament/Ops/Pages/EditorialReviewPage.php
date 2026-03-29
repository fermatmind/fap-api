<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\EditorialReviewChecklist;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;

class EditorialReviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Content Release';

    protected static ?string $navigationLabel = 'Editorial Review';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'editorial-review';

    protected static string $view = 'filament.ops.pages.editorial-review';

    /** @var list<array<string, mixed>> */
    public array $reviewFields = [];

    /** @var list<array<string, mixed>> */
    public array $reviewItems = [];

    public string $typeFilter = 'all';

    public string $reviewStateFilter = 'all';

    public function mount(): void
    {
        $this->refreshReviewSurface();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshReviewSurface();
    }

    public function updatedReviewStateFilter(): void
    {
        $this->refreshReviewSurface();
    }

    public function approveItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);
        $missing = EditorialReviewChecklist::missing($type, $record);

        if ($missing !== []) {
            throw new AuthorizationException('You cannot approve a record with review checklist gaps.');
        }

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, $type, $record);

        Notification::make()
            ->title('Editorial review approved')
            ->success()
            ->send();

        $this->refreshReviewSurface();
    }

    public function requestChangesItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_CHANGES_REQUESTED, $type, $record);

        Notification::make()
            ->title('Changes requested')
            ->warning()
            ->send();

        $this->refreshReviewSurface();
    }

    public function rejectItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_REJECTED, $type, $record);

        Notification::make()
            ->title('Editorial review rejected')
            ->danger()
            ->send();

        $this->refreshReviewSurface();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_release');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.editorial_review');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRelease();
    }

    private function refreshReviewSurface(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $articles = Article::query()
            ->with('seoMeta')
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'draft')
            ->latest('updated_at')
            ->get()
            ->map(fn (Article $record): array => $this->reviewRowForArticle($record));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerGuide $record): array => $this->reviewRowForGuide($record));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerJob $record): array => $this->reviewRowForJob($record));

        $allItems = collect()
            ->concat($articles)
            ->concat($guides)
            ->concat($jobs);

        $filteredItems = $this->typeFilter === 'all'
            ? $allItems
            : $allItems->where('type', $this->typeFilter);

        if ($this->reviewStateFilter !== 'all') {
            $filteredItems = $filteredItems->where('review_state', $this->reviewStateFilter);
        }

        $readyCount = $allItems->where('review_state', EditorialReviewAudit::STATE_READY)->count();
        $approvedCount = $allItems->where('review_state', EditorialReviewAudit::STATE_APPROVED)->count();
        $attentionCount = $allItems->where('review_state', EditorialReviewAudit::STATE_NEEDS_ATTENTION)->count();

        $this->reviewItems = $filteredItems
            ->sortByDesc('updated_at_sort')
            ->values()
            ->all();

        $this->reviewFields = [
            [
                'label' => 'Draft review queue',
                'value' => (string) $allItems->count(),
                'hint' => 'Draft editorial records currently visible to the lightweight review surface.',
            ],
            [
                'label' => 'Ready for release review',
                'value' => (string) $readyCount,
                'hint' => 'Draft records that satisfy the current checklist and are ready for an explicit approval decision.',
            ],
            [
                'label' => 'Approved for release',
                'value' => (string) $approvedCount,
                'hint' => 'Draft records with a current approval decision and no newer edits after that approval.',
            ],
            [
                'label' => 'Needs attention',
                'value' => (string) $attentionCount,
                'hint' => 'Draft records still missing content or SEO inputs before release review is likely to pass cleanly.',
            ],
            [
                'label' => 'Current type filter',
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => 'Filter the review queue by editorial surface.',
            ],
            [
                'label' => 'Current review filter',
                'value' => $this->typeLabel($this->reviewStateFilter),
                'hint' => 'Keep focus on records that are ready now or isolate drafts that still need editorial cleanup.',
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForArticle(Article $record): array
    {
        $missing = EditorialReviewChecklist::missing('article', $record);
        $reviewState = $this->resolveReviewState('article', $record, $missing === []);

        return $this->reviewRow(
            type: 'article',
            id: (int) $record->id,
            typeLabel: 'Article',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: ArticleResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForGuide(CareerGuide $record): array
    {
        $missing = EditorialReviewChecklist::missing('guide', $record);
        $reviewState = $this->resolveReviewState('guide', $record, $missing === []);

        return $this->reviewRow(
            type: 'guide',
            id: (int) $record->id,
            typeLabel: 'Career Guide',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: CareerGuideResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForJob(CareerJob $record): array
    {
        $missing = EditorialReviewChecklist::missing('job', $record);
        $reviewState = $this->resolveReviewState('job', $record, $missing === []);

        return $this->reviewRow(
            type: 'job',
            id: (int) $record->id,
            typeLabel: 'Career Job',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: CareerJobResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(
        string $type,
        int $id,
        string $typeLabel,
        string $title,
        string $locale,
        string $reviewState,
        string $checklistLabel,
        string $updatedAt,
        string $editUrl,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'type_label' => $typeLabel,
            'title' => $title !== '' ? $title : 'Untitled',
            'locale' => $locale !== '' ? $locale : 'Unknown',
            'review_state' => $reviewState,
            'checklist_label' => $checklistLabel,
            'updated_at' => $updatedAt,
            'updated_at_sort' => strtotime($updatedAt) ?: 0,
            'edit_url' => $editUrl,
        ];
    }

    private function typeLabel(string $value): string
    {
        return match ($value) {
            'article' => 'Article',
            'guide' => 'Career Guide',
            'job' => 'Career Job',
            EditorialReviewAudit::STATE_READY => EditorialReviewAudit::label(EditorialReviewAudit::STATE_READY),
            EditorialReviewAudit::STATE_APPROVED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_APPROVED),
            EditorialReviewAudit::STATE_CHANGES_REQUESTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_CHANGES_REQUESTED),
            EditorialReviewAudit::STATE_REJECTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_REJECTED),
            EditorialReviewAudit::STATE_NEEDS_ATTENTION => EditorialReviewAudit::label(EditorialReviewAudit::STATE_NEEDS_ATTENTION),
            default => 'All',
        };
    }

    private function resolveReviewState(string $type, object $record, bool $checklistReady): string
    {
        if (! $checklistReady) {
            return EditorialReviewAudit::STATE_NEEDS_ATTENTION;
        }

        $decision = EditorialReviewAudit::latestState($type, $record);

        return $decision['state'] ?? EditorialReviewAudit::STATE_READY;
    }

    private function resolveRecord(string $type, int $id): object
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to review content.');
        }

        return match ($type) {
            'article' => Article::query()->whereIn('org_id', $this->currentOrgIds())->findOrFail($id),
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'job' => CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            default => throw new AuthorizationException('Unsupported review type.'),
        };
    }
}
