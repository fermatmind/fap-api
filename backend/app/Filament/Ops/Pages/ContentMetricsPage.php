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
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
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
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
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
        $guideDrafts = (clone $guideBaseQuery)
            ->where('status', CareerGuide::STATUS_DRAFT)
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
            ->count();
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
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
            ->where('updated_at', '<', $staleThreshold);
        $guideStaleDrafts = (clone $guideStaleBaseQuery)->count();

        $jobTotal = (clone $jobBaseQuery)->count();
        $jobDrafts = (clone $jobBaseQuery)
            ->where('status', CareerJob::STATUS_DRAFT)
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
            ->count();
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
            ->where(function ($query): void {
                $query->where('lifecycle_state', ContentLifecycleService::STATE_ACTIVE)
                    ->orWhereNull('lifecycle_state');
            })
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
                'label' => __('ops.custom_pages.content_metrics.fields.article_inventory'),
                'value' => (string) $articleTotal,
                'hint' => __('ops.custom_pages.content_metrics.fields.article_inventory_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.public_articles'),
                'value' => (string) $articlePublishedPublic,
                'hint' => __('ops.custom_pages.content_metrics.fields.public_articles_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.career_inventory'),
                'value' => (string) $globalCareerInventory,
                'hint' => __('ops.custom_pages.content_metrics.fields.career_inventory_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.draft_pressure'),
                'value' => (string) $draftQueuePressure,
                'hint' => __('ops.custom_pages.content_metrics.fields.draft_pressure_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.published_footprint'),
                'value' => (string) $publishedSurfaceFootprint,
                'hint' => __('ops.custom_pages.content_metrics.fields.published_footprint_hint'),
            ],
        ];

        $this->scopeFields = [
            [
                'label' => __('ops.custom_pages.content_metrics.fields.taxonomy'),
                'value' => (string) ($categoryCount + $tagCount),
                'hint' => __('ops.custom_pages.content_metrics.fields.taxonomy_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.lifecycle_backlog'),
                'value' => (string) $lifecycleBacklog,
                'hint' => __('ops.custom_pages.content_metrics.fields.lifecycle_backlog_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.article_coverage'),
                'value' => $this->ratioLabel($articlePublishedPublic, $articleTotal),
                'hint' => __('ops.custom_pages.content_metrics.fields.article_coverage_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.article_seo_ready'),
                'value' => $this->ratioLabel($articleIndexable, $articleTotal),
                'hint' => __('ops.custom_pages.content_metrics.fields.article_seo_ready_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.career_coverage'),
                'value' => $this->ratioLabel($guidePublishedPublic + $jobPublishedPublic, $globalCareerInventory),
                'hint' => __('ops.custom_pages.content_metrics.fields.career_coverage_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_metrics.fields.visibility_gaps'),
                'value' => (string) $visibilityGaps,
                'kind' => 'pill',
                'state' => $visibilityGaps > 0 ? 'warning' : 'success',
                'hint' => __('ops.custom_pages.content_metrics.fields.visibility_gaps_hint'),
            ],
        ];

        $this->freshnessCards = [
            $this->freshnessCard(
                __('ops.custom_pages.content_metrics.fields.article_stale'),
                __('ops.custom_pages.content_metrics.fields.article_stale_desc'),
                $articleStaleDrafts,
                __('ops.custom_pages.editorial_operations.surfaces.current_org'),
                (clone $articleStaleBaseQuery)->latest('updated_at')->value('title'),
                'article'
            ),
            $this->freshnessCard(
                __('ops.custom_pages.content_metrics.fields.guide_stale'),
                __('ops.custom_pages.content_metrics.fields.guide_stale_desc'),
                $guideStaleDrafts,
                __('ops.custom_pages.common.values.global_content'),
                (clone $guideStaleBaseQuery)->latest('updated_at')->value('title'),
                'guide'
            ),
            $this->freshnessCard(
                __('ops.custom_pages.content_metrics.fields.job_stale'),
                __('ops.custom_pages.content_metrics.fields.job_stale_desc'),
                $jobStaleDrafts,
                __('ops.custom_pages.common.values.global_content'),
                (clone $jobStaleBaseQuery)->latest('updated_at')->value('title'),
                'job'
            ),
            [
                'title' => __('ops.custom_pages.content_metrics.fields.publish_gap_watch'),
                'description' => __('ops.custom_pages.content_metrics.fields.publish_gap_watch_desc'),
                'meta' => __('ops.custom_pages.content_metrics.fields.visibility_meta', ['count' => $visibilityGaps]),
                'value' => (string) $visibilityGaps,
                'status' => $visibilityGaps > 0 ? __('ops.custom_pages.common.values.needs_attention') : __('ops.custom_pages.common.values.healthy'),
                'status_state' => $visibilityGaps > 0 ? 'warning' : 'success',
                'latest_title' => __('ops.custom_pages.content_metrics.fields.no_stale_action'),
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
            'meta' => __('ops.custom_pages.content_metrics.fields.stale_meta', ['scope' => $scope, 'count' => $count]),
            'value' => (string) $count,
            'status' => $count > 0 ? __('ops.custom_pages.common.values.needs_attention') : __('ops.custom_pages.common.values.healthy'),
            'status_state' => $count > 0 ? 'warning' : 'success',
            'latest_title' => trim((string) $latestTitle) !== '' ? trim((string) $latestTitle) : __('ops.custom_pages.common.values.no_recent_record'),
            'action_type' => $actionType,
            'can_archive' => $count > 0 && ContentAccess::canRelease(),
        ];
    }
}
