<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Support\ContentAccess;
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

    protected static ?string $navigationGroup = 'Content Release';

    protected static ?string $navigationLabel = 'Content Release';

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

    public static function canAccess(): bool
    {
        return ContentAccess::canRelease();
    }

    public function releaseItem(string $type, int $id): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release content.');
        }

        match ($type) {
            'article' => ArticleResource::releaseRecord(
                Article::query()->whereIn('org_id', $this->tenantOrgIds())->findOrFail($id)
            ),
            'guide' => CareerGuideResource::releaseRecord(
                CareerGuide::query()->findOrFail($id)
            ),
            'job' => CareerJobResource::releaseRecord(
                CareerJob::query()->findOrFail($id)
            ),
            default => throw new AuthorizationException('Unsupported content type.'),
        };

        $this->refreshWorkspace();
    }

    private function refreshWorkspace(): void
    {
        $tenantOrgIds = $this->tenantOrgIds();

        $articles = Article::query()
            ->whereIn('org_id', $tenantOrgIds)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (Article $record): array => $this->releaseRow(
                type: 'article',
                typeLabel: 'Article',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: ArticleResource::getUrl('edit', ['record' => $record]),
                indexUrl: ArticleResource::getUrl(),
            ));

        $guides = CareerGuide::query()
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerGuide $record): array => $this->releaseRow(
                type: 'guide',
                typeLabel: 'Career Guide',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: CareerGuideResource::getUrl('edit', ['record' => $record]),
                indexUrl: CareerGuideResource::getUrl(),
            ));

        $jobs = CareerJob::query()
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerJob $record): array => $this->releaseRow(
                type: 'job',
                typeLabel: 'Career Job',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
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
        $publishedCount = $allItems->where('status', 'published')->count();

        $this->releaseFields = [
            [
                'label' => 'Draft queue',
                'value' => (string) $draftCount,
                'hint' => 'Draft content waiting inside the lightweight review-and-release surface.',
            ],
            [
                'label' => 'Published content',
                'value' => (string) $publishedCount,
                'hint' => 'Published records already cleared by a release-capable operator.',
            ],
            [
                'label' => 'Current type filter',
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => 'Filter the release queue by content type without leaving the workspace.',
            ],
            [
                'label' => 'Current status filter',
                'value' => $this->typeLabel($this->statusFilter),
                'hint' => 'Draft stays the default review state. Switch to all or published for audit checks.',
            ],
            [
                'label' => 'Release permission',
                'value' => ContentAccess::canRelease() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'failed',
                'hint' => 'Release permission is the approval boundary in this lightweight phase.',
            ],
        ];

        $this->surfaceCards = [
            $this->surfaceCard('Articles', $articles, ArticleResource::getUrl()),
            $this->surfaceCard('Career Guides', $guides, CareerGuideResource::getUrl()),
            $this->surfaceCard('Career Jobs', $jobs, CareerJobResource::getUrl()),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function tenantOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [0, $orgId] : [0];
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
            'locale' => $locale,
            'visibility' => $visibility,
            'updated_at' => $updatedAt,
            'updated_at_sort' => strtotime($updatedAt) ?: 0,
            'edit_url' => $editUrl,
            'index_url' => $indexUrl,
            'releaseable' => $status === 'draft',
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
            'meta' => $records->count().' matching',
            'description' => 'Open the corresponding resource list for full editing, audit, and release follow-up.',
            'index_url' => $indexUrl,
        ];
    }

    private function typeLabel(string $value): string
    {
        return match ($value) {
            'all' => 'All',
            'article' => 'Article',
            'guide' => 'Career Guide',
            'job' => 'Career Job',
            'draft' => 'Draft',
            'published' => 'Published',
            default => ucfirst($value),
        };
    }
}
