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
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class ContentReleasePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'content-release';

    protected static string $view = 'filament.ops.pages.content-release';

    /** @var list<array<string, mixed>> */
    public array $releaseFields = [];

    /** @var list<array<string, mixed>> */
    public array $releaseItems = [];

    /** @var list<array<string, mixed>> */
    public array $surfaceCards = [];

    public string $typeFilter = 'all';

    public string $statusFilter = 'draft';

    public function mount(): void
    {
        $this->refreshWorkspace();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshWorkspace();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshWorkspace();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_release');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_release');
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.content_release.title');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRelease();
    }

    public function releaseItem(string $type, int $id): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.release_content_forbidden'));
        }

        $record = match ($type) {
            'article' => Article::query()->whereIn('org_id', $this->currentOrgIds())->findOrFail($id),
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'job' => CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            default => throw new AuthorizationException(__('ops.custom_pages.common.errors.unsupported_content_type')),
        };

        if ($this->reviewState($type, $record) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.must_be_approved_before_publish'));
        }

        match ($type) {
            'article' => ArticleResource::releaseRecord($record, 'release_workspace'),
            'guide' => CareerGuideResource::releaseRecord($record, 'release_workspace'),
            'job' => CareerJobResource::releaseRecord($record, 'release_workspace'),
        };

        $this->refreshWorkspace();
    }

    private function refreshWorkspace(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $articles = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (Article $record): array => $this->releaseRow(
                type: 'article',
                typeLabel: __('ops.custom_pages.common.filters.article'),
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('article', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? __('ops.custom_pages.common.values.public') : __('ops.custom_pages.common.values.private'),
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
                editUrl: ArticleResource::getUrl('edit', ['record' => $record]),
                indexUrl: ArticleResource::getUrl(),
            ));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerGuide $record): array => $this->releaseRow(
                type: 'guide',
                typeLabel: __('ops.custom_pages.common.filters.career_guide'),
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('guide', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? __('ops.custom_pages.common.values.public') : __('ops.custom_pages.common.values.private'),
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
                editUrl: CareerGuideResource::getUrl('edit', ['record' => $record]),
                indexUrl: CareerGuideResource::getUrl(),
            ));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerJob $record): array => $this->releaseRow(
                type: 'job',
                typeLabel: __('ops.custom_pages.common.filters.career_job'),
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('job', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? __('ops.custom_pages.common.values.public') : __('ops.custom_pages.common.values.private'),
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
                editUrl: CareerJobResource::getUrl('edit', ['record' => $record]),
                indexUrl: CareerJobResource::getUrl(),
            ));

        $allItems = collect()
            ->concat($articles)
            ->concat($guides)
            ->concat($jobs);

        $filteredItems = $this->typeFilter === 'all'
            ? $allItems
            : $allItems->where('type', $this->typeFilter);

        $this->releaseItems = $filteredItems
            ->sortByDesc('updated_at_sort')
            ->values()
            ->all();

        $draftCount = $allItems->where('status', 'draft')->count();
        $approvedCount = $allItems->where('review_state', EditorialReviewAudit::STATE_APPROVED)->count();
        $publishedCount = $allItems->where('status', 'published')->count();

        $this->releaseFields = [
            [
                'label' => __('ops.custom_pages.content_release.fields.draft_queue'),
                'value' => (string) $draftCount,
                'hint' => __('ops.custom_pages.content_release.fields.draft_queue_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_release.fields.approved'),
                'value' => (string) $approvedCount,
                'hint' => __('ops.custom_pages.content_release.fields.approved_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_release.fields.published'),
                'value' => (string) $publishedCount,
                'hint' => __('ops.custom_pages.content_release.fields.published_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_release.fields.type_filter'),
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => __('ops.custom_pages.content_release.fields.type_filter_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_release.fields.status_filter'),
                'value' => $this->typeLabel($this->statusFilter),
                'hint' => __('ops.custom_pages.content_release.fields.status_filter_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_release.fields.permission'),
                'value' => ContentAccess::canRelease() ? __('ops.custom_pages.common.values.enabled') : __('ops.custom_pages.common.values.missing'),
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'failed',
                'hint' => __('ops.custom_pages.content_release.fields.permission_hint'),
            ],
        ];

        $this->surfaceCards = [
            $this->surfaceCard(__('ops.custom_pages.common.filters.articles'), $articles, ArticleResource::getUrl()),
            $this->surfaceCard(__('ops.custom_pages.common.filters.career_guides'), $guides, CareerGuideResource::getUrl()),
            $this->surfaceCard(__('ops.custom_pages.common.filters.career_jobs'), $jobs, CareerJobResource::getUrl()),
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
    private function releaseRow(
        string $type,
        string $typeLabel,
        int $id,
        string $title,
        string $status,
        string $reviewState,
        string $locale,
        string $visibility,
        string $updatedAt,
        string $editUrl,
        string $indexUrl,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'type_label' => $typeLabel,
            'title' => $title,
            'status' => $status,
            'status_label' => $this->typeLabel($status),
            'review_state' => $reviewState,
            'locale' => $locale,
            'visibility' => $visibility,
            'updated_at' => $updatedAt,
            'updated_at_sort' => strtotime($updatedAt) ?: 0,
            'edit_url' => $editUrl,
            'index_url' => $indexUrl,
            'releaseable' => $status === 'draft' && $reviewState === EditorialReviewAudit::STATE_APPROVED,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function surfaceCard(string $title, Collection $records, string $indexUrl): array
    {
        return [
            'title' => $title,
            'meta' => __('ops.custom_pages.content_release.matching', ['count' => $records->count()]),
            'description' => __('ops.custom_pages.content_release.surface_desc'),
            'index_url' => $indexUrl,
        ];
    }

    private function typeLabel(string $value): string
    {
        return match ($value) {
            'all' => __('ops.custom_pages.common.filters.all'),
            'article' => __('ops.custom_pages.common.filters.article'),
            'guide' => __('ops.custom_pages.common.filters.career_guide'),
            'job' => __('ops.custom_pages.common.filters.career_job'),
            'draft' => __('ops.custom_pages.common.filters.draft'),
            'published' => __('ops.custom_pages.common.filters.published'),
            default => ucfirst($value),
        };
    }

    private function reviewState(string $type, object $record): string
    {
        if ((string) data_get($record, 'status') === 'published') {
            return EditorialReviewAudit::STATE_APPROVED;
        }

        $checklistReady = EditorialReviewChecklist::missing($type, $record) === [];
        $decision = EditorialReviewAudit::latestState($type, $record);

        if (is_array($decision) && ($decision['state'] ?? null) !== null) {
            $state = (string) $decision['state'];

            if ($state === EditorialReviewAudit::STATE_READY && ! $checklistReady) {
                return EditorialReviewAudit::STATE_NEEDS_ATTENTION;
            }

            return $state;
        }

        return $checklistReady
            ? EditorialReviewAudit::STATE_READY
            : EditorialReviewAudit::STATE_NEEDS_ATTENTION;
    }
}
