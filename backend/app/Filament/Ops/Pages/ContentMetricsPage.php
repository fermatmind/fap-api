<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\ContentLifecycleService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

class ContentMetricsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'Content Metrics';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'content-metrics';

    protected static string $view = 'filament.ops.pages.content-metrics';

    /** @var list<array<string, mixed>> */
    public array $headlineFields = [];

    /** @var list<array<string, mixed>> */
    public array $scopeFields = [];

    /** @var list<array<string, mixed>> */
    public array $freshnessCards = [];

    public int $staleThresholdDays = 14;

    public function mount(): void
    {
        $this->refreshMetrics();
    }

    public function archiveStale(string $type, ContentLifecycleService $service, AuditLogger $audit): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to archive stale content.');
        }

        $result = $service->applyToStaleDrafts(
            $type,
            ContentLifecycleService::ACTION_ARCHIVE,
            $this->currentOrgIds(),
            Carbon::now()->subDays($this->staleThresholdDays)
        );

        $audit->log(
            request(),
            'content_lifecycle_stale_archive',
            'content_lifecycle_batch',
            null,
            [
                'type' => $type,
                'processed_count' => (int) ($result['processed_count'] ?? 0),
            ]
        );

        Notification::make()
            ->title('Stale drafts archived')
            ->body((string) ($result['processed_count'] ?? 0).' record(s) archived from the stale queue.')
            ->success()
            ->send();

        $this->refreshMetrics();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_metrics');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    private function refreshMetrics(): void
    {
        $currentOrgIds = $this->currentOrgIds();
        $staleThreshold = Carbon::now()->subDays($this->staleThresholdDays);

        $articleBaseQuery = Article::query()->whereIn('org_id', $currentOrgIds);
        $articleTotal = (clone $articleBaseQuery)->count();
        $articlePublishedPublic = (clone $articleBaseQuery)
            ->where('status', 'published')
            ->where('is_public', true)
            ->count();
        $articlePublishedPrivate = (clone $articleBaseQuery)
            ->where('status', 'published')
            ->where('is_public', false)
            ->count();
        $articleIndexable = (clone $articleBaseQuery)
            ->where('is_indexable', true)
            ->count();
        $articleDrafts = (clone $articleBaseQuery)
            ->where('status', 'draft')
            ->count();
        $articleArchived = (clone $articleBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_ARCHIVED)
            ->count();
        $articleSoftDeleted = (clone $articleBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_SOFT_DELETED)
            ->count();
        $articleDownranked = (clone $articleBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_DOWNRANKED)
            ->count();
        $articleStaleBaseQuery = (clone $articleBaseQuery)
            ->where('status', 'draft')
            ->where('updated_at', '<', $staleThreshold);
        $articleStaleDrafts = (clone $articleStaleBaseQuery)->count();

        $categoryCount = ArticleCategory::query()->whereIn('org_id', $currentOrgIds)->count();
        $tagCount = ArticleTag::query()->whereIn('org_id', $currentOrgIds)->count();

        $guideBaseQuery = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);
        $jobBaseQuery = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);

        $guideTotal = (clone $guideBaseQuery)->count();
        $guideDrafts = (clone $guideBaseQuery)->where('status', CareerGuide::STATUS_DRAFT)->count();
        $guidePublishedPublic = (clone $guideBaseQuery)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->count();
        $guidePublishedPrivate = (clone $guideBaseQuery)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', false)
            ->count();
        $guideArchived = (clone $guideBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_ARCHIVED)
            ->count();
        $guideSoftDeleted = (clone $guideBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_SOFT_DELETED)
            ->count();
        $guideDownranked = (clone $guideBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_DOWNRANKED)
            ->count();
        $guideStaleBaseQuery = (clone $guideBaseQuery)
            ->where('status', CareerGuide::STATUS_DRAFT)
            ->where('updated_at', '<', $staleThreshold);
        $guideStaleDrafts = (clone $guideStaleBaseQuery)->count();

        $jobTotal = (clone $jobBaseQuery)->count();
        $jobDrafts = (clone $jobBaseQuery)->where('status', CareerJob::STATUS_DRAFT)->count();
        $jobPublishedPublic = (clone $jobBaseQuery)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->count();
        $jobPublishedPrivate = (clone $jobBaseQuery)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', false)
            ->count();
        $jobArchived = (clone $jobBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_ARCHIVED)
            ->count();
        $jobSoftDeleted = (clone $jobBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_SOFT_DELETED)
            ->count();
        $jobDownranked = (clone $jobBaseQuery)
            ->where('lifecycle_state', ContentLifecycleService::STATE_DOWNRANKED)
            ->count();
        $jobStaleBaseQuery = (clone $jobBaseQuery)
            ->where('status', CareerJob::STATUS_DRAFT)
            ->where('updated_at', '<', $staleThreshold);
        $jobStaleDrafts = (clone $jobStaleBaseQuery)->count();

        $draftQueuePressure = $articleDrafts + $guideDrafts + $jobDrafts;
        $publishedSurfaceFootprint = $articlePublishedPublic + $guidePublishedPublic + $jobPublishedPublic;
        $globalCareerInventory = $guideTotal + $jobTotal;
        $visibilityGaps = $articlePublishedPrivate + $guidePublishedPrivate + $jobPublishedPrivate;
        $lifecycleBacklog = $articleArchived + $articleSoftDeleted + $articleDownranked
            + $guideArchived + $guideSoftDeleted + $guideDownranked
            + $jobArchived + $jobSoftDeleted + $jobDownranked;

        $this->headlineFields = [
            [
                'label' => 'Current org article inventory',
                'value' => (string) $articleTotal,
                'hint' => 'Article records attached to the selected Ops organization.',
            ],
            [
                'label' => 'Current org public articles',
                'value' => (string) $articlePublishedPublic,
                'hint' => 'Selected-org articles already published and public under the current API contract.',
            ],
            [
                'label' => 'Global career inventory',
                'value' => (string) $globalCareerInventory,
                'hint' => 'Career guides and jobs remain global authoring surfaces with org_id=0.',
            ],
            [
                'label' => 'Draft queue pressure',
                'value' => (string) $draftQueuePressure,
                'hint' => 'Draft editorial objects currently waiting for review or publish handoff.',
            ],
            [
                'label' => 'Published surface footprint',
                'value' => (string) $publishedSurfaceFootprint,
                'hint' => 'Public article, guide, and job records visible through the current delivery contract.',
            ],
        ];

        $this->scopeFields = [
            [
                'label' => 'Current org taxonomy',
                'value' => (string) ($categoryCount + $tagCount),
                'hint' => 'Categories and tags available to the selected organization.',
            ],
            [
                'label' => 'Lifecycle backlog',
                'value' => (string) $lifecycleBacklog,
                'hint' => 'Records already moved into down-ranked, archived, or soft-deleted states.',
            ],
            [
                'label' => 'Article publish coverage',
                'value' => $this->ratioLabel($articlePublishedPublic, $articleTotal),
                'hint' => 'Public article coverage inside the selected org boundary.',
            ],
            [
                'label' => 'Article SEO-ready',
                'value' => $this->ratioLabel($articleIndexable, $articleTotal),
                'hint' => 'Selected-org articles currently marked indexable.',
            ],
            [
                'label' => 'Global career publish coverage',
                'value' => $this->ratioLabel($guidePublishedPublic + $jobPublishedPublic, $globalCareerInventory),
                'hint' => 'Public global career coverage across guides and jobs.',
            ],
            [
                'label' => 'Visibility gaps',
                'value' => (string) $visibilityGaps,
                'kind' => 'pill',
                'state' => $visibilityGaps > 0 ? 'warning' : 'success',
                'hint' => 'Published records that are still not public and therefore not reachable on the public contract.',
            ],
        ];

        $this->freshnessCards = [
            $this->freshnessCard(
                'Current org stale drafts',
                'Org-scoped article drafts older than 14 days.',
                $articleStaleDrafts,
                'Current org',
                (clone $articleStaleBaseQuery)->latest('updated_at')->value('title'),
                'article'
            ),
            $this->freshnessCard(
                'Global guide stale drafts',
                'Global career guide drafts older than 14 days.',
                $guideStaleDrafts,
                'Global content',
                (clone $guideStaleBaseQuery)->latest('updated_at')->value('title'),
                'guide'
            ),
            $this->freshnessCard(
                'Global job stale drafts',
                'Global career job drafts older than 14 days.',
                $jobStaleDrafts,
                'Global content',
                (clone $jobStaleBaseQuery)->latest('updated_at')->value('title'),
                'job'
            ),
            [
                'title' => 'Publish gap watch',
                'description' => 'Keep an eye on published-but-not-public mismatches before they turn into confusing release support work.',
                'meta' => 'Visibility gaps | '.$visibilityGaps.' records',
                'value' => (string) $visibilityGaps,
                'status' => $visibilityGaps > 0 ? 'Needs cleanup' : 'Healthy',
                'status_state' => $visibilityGaps > 0 ? 'warning' : 'success',
                'latest_title' => 'No stale queue action',
                'action_type' => null,
                'can_archive' => false,
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

    private function ratioLabel(int $value, int $total): string
    {
        if ($total <= 0) {
            return '0% (0/0)';
        }

        $ratio = (int) round(($value / $total) * 100);

        return $ratio.'% ('.$value.'/'.$total.')';
    }

    /**
     * @return array<string, mixed>
     */
    private function freshnessCard(
        string $title,
        string $description,
        int $count,
        string $scope,
        ?string $latestTitle,
        string $actionType,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'meta' => $scope.' | '.$count.' stale drafts',
            'value' => (string) $count,
            'status' => $count > 0 ? 'Needs attention' : 'Healthy',
            'status_state' => $count > 0 ? 'warning' : 'success',
            'latest_title' => trim((string) $latestTitle) !== '' ? trim((string) $latestTitle) : 'No recent record',
            'action_type' => $actionType,
            'can_archive' => $count > 0 && ContentAccess::canRelease(),
        ];
    }
}
