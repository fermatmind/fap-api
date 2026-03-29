<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\SeoOperationsService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class SeoOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'SEO Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'seo-operations';

    protected static string $view = 'filament.ops.pages.seo-operations';

    public string $typeFilter = 'all';

    public string $issueFilter = 'all';

    public string $bulkAction = SeoOperationsService::ACTION_FILL_METADATA;

    /** @var list<string> */
    public array $selectedTargets = [];

    /** @var list<array<string, mixed>> */
    public array $headlineFields = [];

    /** @var list<array<string, mixed>> */
    public array $coverageFields = [];

    /** @var list<array<string, mixed>> */
    public array $growthFields = [];

    /** @var list<array<string, mixed>> */
    public array $attentionCards = [];

    /** @var list<array<string, mixed>> */
    public array $issueQueue = [];

    public int $issueQueueElapsedMs = 0;

    public function mount(SeoOperationsService $service): void
    {
        $this->refreshDashboard($service);
    }

    public function updatedTypeFilter(): void
    {
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedIssueFilter(): void
    {
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function applyBulkAction(SeoOperationsService $service, AuditLogger $audit): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException('You do not have permission to operate SEO actions.');
        }

        if ($this->selectedTargets === []) {
            Notification::make()
                ->title('Select at least one SEO issue row')
                ->warning()
                ->send();

            return;
        }

        $result = $service->applyBulkAction($this->selectedTargets, $this->bulkAction, $this->currentOrgIds());
        $updatedCount = (int) ($result['updated_count'] ?? 0);

        $audit->log(
            request(),
            'seo_operations_bulk_action',
            'SeoOperations',
            null,
            [
                'action' => $this->bulkAction,
                'type_filter' => $this->typeFilter,
                'issue_filter' => $this->issueFilter,
                'selection_count' => count($this->selectedTargets),
                'updated_count' => $updatedCount,
                'targets' => $result['updated_keys'] ?? [],
            ]
        );

        $this->selectedTargets = [];
        $this->refreshDashboard($service);

        Notification::make()
            ->title('SEO operations applied')
            ->body('Updated '.$updatedCount.' records.')
            ->success()
            ->send();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.seo_operations');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }

    private function refreshDashboard(SeoOperationsService $service): void
    {
        $currentOrgIds = $this->currentOrgIds();

        /** @var Collection<int, Article> $articles */
        $articles = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->with('seoMeta')
            ->latest('updated_at')
            ->get();
        /** @var Collection<int, CareerGuide> $guides */
        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with('seoMeta')
            ->latest('updated_at')
            ->get();
        /** @var Collection<int, CareerJob> $jobs */
        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with('seoMeta')
            ->latest('updated_at')
            ->get();

        $articleTotal = $articles->count();
        $guideTotal = $guides->count();
        $jobTotal = $jobs->count();
        $careerTotal = $guideTotal + $jobTotal;

        $articleSeoReady = $this->countSeoReady($service, 'article', $articles);
        $guideSeoReady = $this->countSeoReady($service, 'guide', $guides);
        $jobSeoReady = $this->countSeoReady($service, 'job', $jobs);
        $careerSeoReady = $guideSeoReady + $jobSeoReady;

        $articleCanonicalCoverage = $this->countCanonicalCoverage($service, 'article', $articles);
        $guideCanonicalCoverage = $this->countCanonicalCoverage($service, 'guide', $guides);
        $jobCanonicalCoverage = $this->countCanonicalCoverage($service, 'job', $jobs);

        $articleSocialCoverage = $this->countSocialCoverage($articles);
        $careerSocialCoverage = $this->countSocialCoverage($guides) + $this->countSocialCoverage($jobs);

        $indexableFootprint = $articles->where('is_indexable', true)->count()
            + $guides->where('is_indexable', true)->count()
            + $jobs->where('is_indexable', true)->count();

        $publicSeoReady = $this->countGrowthReady($service, 'article', $articles)
            + $this->countGrowthReady($service, 'guide', $guides)
            + $this->countGrowthReady($service, 'job', $jobs);

        $seoAttentionQueue = ($articleTotal - $articleSeoReady)
            + ($guideTotal - $guideSeoReady)
            + ($jobTotal - $jobSeoReady);

        $robotsGaps = $this->countRobotsGaps($service, 'article', $articles)
            + $this->countRobotsGaps($service, 'guide', $guides)
            + $this->countRobotsGaps($service, 'job', $jobs);

        $publishedDiscoveryBlocked = $this->countPublishedDiscoveryBlocked($service, 'article', $articles)
            + $this->countPublishedDiscoveryBlocked($service, 'guide', $guides)
            + $this->countPublishedDiscoveryBlocked($service, 'job', $jobs);

        $socialPreviewBlocked = $this->countIssueCode($service, 'social', 'article', $articles)
            + $this->countIssueCode($service, 'social', 'guide', $guides)
            + $this->countIssueCode($service, 'social', 'job', $jobs);

        $noindexInventory = $articles->where('is_indexable', false)->count()
            + $guides->where('is_indexable', false)->count()
            + $jobs->where('is_indexable', false)->count();

        $this->headlineFields = [
            [
                'label' => 'Current org article SEO-ready',
                'value' => $this->ratioLabel($articleSeoReady, $articleTotal),
                'hint' => 'Selected-org article coverage for metadata, canonical, robots, indexability, and growth blockers.',
            ],
            [
                'label' => 'Global career SEO-ready',
                'value' => $this->ratioLabel($careerSeoReady, $careerTotal),
                'hint' => 'Global guide and job coverage for the visible SEO authoring surface.',
            ],
            [
                'label' => 'Indexable footprint',
                'value' => (string) $indexableFootprint,
                'hint' => 'Visible records currently marked indexable across article and global career surfaces.',
            ],
            [
                'label' => 'Growth-ready records',
                'value' => (string) $publicSeoReady,
                'hint' => 'Published, public, indexable, and discovery-ready records.',
            ],
            [
                'label' => 'SEO attention queue',
                'value' => (string) $seoAttentionQueue,
                'hint' => 'Visible content objects still missing at least one operational SEO requirement.',
            ],
        ];

        $this->coverageFields = [
            [
                'label' => 'Article canonical coverage',
                'value' => $this->ratioLabel($articleCanonicalCoverage, $articleTotal),
                'hint' => 'Current-org article canonical URL alignment.',
            ],
            [
                'label' => 'Article social coverage',
                'value' => $this->ratioLabel($articleSocialCoverage, $articleTotal),
                'hint' => 'Current-org Open Graph coverage for articles.',
            ],
            [
                'label' => 'Guide canonical coverage',
                'value' => $this->ratioLabel($guideCanonicalCoverage, $guideTotal),
                'hint' => 'Global career guide canonical URL alignment.',
            ],
            [
                'label' => 'Job canonical coverage',
                'value' => $this->ratioLabel($jobCanonicalCoverage, $jobTotal),
                'hint' => 'Global career job canonical URL alignment.',
            ],
            [
                'label' => 'Robots gaps',
                'value' => (string) $robotsGaps,
                'kind' => 'pill',
                'state' => $robotsGaps > 0 ? 'warning' : 'success',
                'hint' => 'Records where robots still drift from the current indexability contract.',
            ],
        ];

        $this->growthFields = [
            [
                'label' => 'Published discovery blockers',
                'value' => (string) $publishedDiscoveryBlocked,
                'hint' => 'Published and public records still blocked from SEO discovery.',
            ],
            [
                'label' => 'Social preview blockers',
                'value' => (string) $socialPreviewBlocked,
                'hint' => 'Records missing Open Graph or Twitter preview support.',
            ],
            [
                'label' => 'Noindex inventory',
                'value' => (string) $noindexInventory,
                'hint' => 'Visible records intentionally excluded from discovery today.',
            ],
            [
                'label' => 'Growth-ready ratio',
                'value' => $this->ratioLabel($publicSeoReady, $articleTotal + $careerTotal),
                'hint' => 'Visible content already ready for search and social discovery.',
            ],
        ];

        $this->attentionCards = [
            $this->attentionCard(
                'Article SEO gaps',
                'Current-org articles that still need metadata, canonical, robots, or discoverability fixes.',
                $articleTotal - $articleSeoReady,
                'Current org',
                $this->latestIssueTitle($service, 'article', $articles)
            ),
            $this->attentionCard(
                'Career guide SEO gaps',
                'Global guides that still need operational SEO fixes before they should be treated as growth inventory.',
                $guideTotal - $guideSeoReady,
                'Global content',
                $this->latestIssueTitle($service, 'guide', $guides)
            ),
            $this->attentionCard(
                'Career job SEO gaps',
                'Global jobs that still need operational SEO fixes before they should be treated as growth inventory.',
                $jobTotal - $jobSeoReady,
                'Global content',
                $this->latestIssueTitle($service, 'job', $jobs)
            ),
            [
                'title' => 'Growth blockers',
                'description' => 'Published records that are still blocked by noindex, canonical drift, robots drift, or missing metadata.',
                'meta' => 'Visible content | '.$publishedDiscoveryBlocked.' records need discovery fixes',
                'value' => (string) $publishedDiscoveryBlocked,
                'status' => $publishedDiscoveryBlocked > 0 ? 'Needs attention' : 'Healthy',
                'status_state' => $publishedDiscoveryBlocked > 0 ? 'warning' : 'success',
                'latest_title' => $this->latestGrowthBlockedTitle($service, $articles, $guides, $jobs),
            ],
        ];

        $issueQueue = $service->buildIssueQueue($currentOrgIds, $this->typeFilter, $this->issueFilter);
        $this->issueQueue = $issueQueue['items'] ?? [];
        $this->issueQueueElapsedMs = (int) ($issueQueue['elapsed_ms'] ?? 0);
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countSeoReady(SeoOperationsService $service, string $type, Collection $records): int
    {
        return $records->filter(fn (object $record): bool => $service->isSeoReady($type, $record))->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countCanonicalCoverage(SeoOperationsService $service, string $type, Collection $records): int
    {
        return $records->filter(fn (object $record): bool => trim((string) data_get($record, 'seoMeta.canonical_url', '')) === ($service->expectedCanonical($type, $record) ?? ''))->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countSocialCoverage(Collection $records): int
    {
        return $records->filter(function (object $record): bool {
            return trim((string) data_get($record, 'seoMeta.og_title', '')) !== ''
                && trim((string) data_get($record, 'seoMeta.og_description', '')) !== ''
                && trim((string) data_get($record, 'seoMeta.og_image_url', '')) !== '';
        })->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countGrowthReady(SeoOperationsService $service, string $type, Collection $records): int
    {
        return $records->filter(fn (object $record): bool => $service->isGrowthReady($type, $record))->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countRobotsGaps(SeoOperationsService $service, string $type, Collection $records): int
    {
        return $this->countIssueCode($service, SeoOperationsService::ISSUE_ROBOTS, $type, $records);
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countIssueCode(SeoOperationsService $service, string $issueCode, string $type, Collection $records): int
    {
        return $records->filter(function (object $record) use ($service, $issueCode, $type): bool {
            return collect($service->issuesFor($type, $record))
                ->contains(fn (array $issue): bool => ($issue['code'] ?? null) === $issueCode);
        })->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function countPublishedDiscoveryBlocked(SeoOperationsService $service, string $type, Collection $records): int
    {
        return $records->filter(fn (object $record): bool => $service->growthSignal($type, $record) === 'Blocked by noindex'
            || $service->growthSignal($type, $record) === 'Published with discovery blockers')->count();
    }

    /**
     * @param  Collection<int, object>  $records
     */
    private function latestIssueTitle(SeoOperationsService $service, string $type, Collection $records): ?string
    {
        $record = $records->first(fn (object $item): bool => $service->issuesFor($type, $item) !== []);

        return is_object($record) ? trim((string) data_get($record, 'title', '')) : null;
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @param  Collection<int, CareerGuide>  $guides
     * @param  Collection<int, CareerJob>  $jobs
     */
    private function latestGrowthBlockedTitle(
        SeoOperationsService $service,
        Collection $articles,
        Collection $guides,
        Collection $jobs,
    ): string {
        $candidates = collect([
            $articles->first(fn (Article $record): bool => str_contains($service->growthSignal('article', $record), 'Blocked')
                || str_contains($service->growthSignal('article', $record), 'Published')),
            $guides->first(fn (CareerGuide $record): bool => str_contains($service->growthSignal('guide', $record), 'Blocked')
                || str_contains($service->growthSignal('guide', $record), 'Published')),
            $jobs->first(fn (CareerJob $record): bool => str_contains($service->growthSignal('job', $record), 'Blocked')
                || str_contains($service->growthSignal('job', $record), 'Published')),
        ])->filter(fn ($record): bool => is_object($record));

        /** @var object|null $latest */
        $latest = $candidates->sortByDesc(static fn (object $record): string => (string) optional(data_get($record, 'updated_at'))->toISOString())->first();

        return trim((string) data_get($latest, 'title', '')) !== '' ? trim((string) data_get($latest, 'title', '')) : 'No recent record';
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
     * @return array<string, string>
     */
    private function attentionCard(
        string $title,
        string $description,
        int $count,
        string $scope,
        ?string $latestTitle,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'meta' => $scope.' | '.$count.' records need SEO work',
            'value' => (string) $count,
            'status' => $count > 0 ? 'Needs attention' : 'Healthy',
            'status_state' => $count > 0 ? 'warning' : 'success',
            'latest_title' => trim((string) $latestTitle) !== '' ? trim((string) $latestTitle) : 'No recent record',
        ];
    }
}
