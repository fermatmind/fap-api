<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\OpsDeployEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\SeoOperationsService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueWorkflowService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Throwable;

class SeoOperationsPage extends Page
{
    public const MAX_RECORDS_PER_CONTENT_TYPE = 500;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'seo-operations';

    protected static string $view = 'filament.ops.pages.seo-operations';

    public string $typeFilter = 'all';

    public string $issueFilter = 'all';

    public string $scopeFilter = 'combined';

    public string $activeWorkspace = 'overview';

    public string $savedView = 'all';

    public int $gscDays = 28;

    public string $gscDevice = 'all';

    public string $gscCountry = 'all';

    public string $gscLocale = 'all';

    public string $selectedIssueUid = '';

    public string $workflowAction = SeoIssueWorkflowService::ACTION_ASSIGN;

    public string $ignoreReason = '';

    public string $ignoredUntil = '';

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

    /** @var list<array<string, mixed>> */
    public array $healthBand = [];

    /** @var list<array<string, mixed>> */
    public array $issueBreakdown = [];

    public bool $seoIntelAvailable = false;

    /** @var array<string, mixed> */
    public array $searchPerformance = [];

    /** @var list<array<string, mixed>> */
    public array $opportunityQueue = [];

    /** @var list<array<string, mixed>> */
    public array $executionQueue = [];

    /** @var list<array<string, mixed>> */
    public array $dataSources = [];

    /** @var list<array<string, mixed>> */
    public array $scopeSummary = [];

    /** @var list<array<string, mixed>> */
    public array $deploymentEvents = [];

    public function mount(SeoOperationsService $service): void
    {
        $this->refreshDashboard($service);
        $this->refreshSeoIntel();
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

    public function updatedScopeFilter(): void
    {
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedGscDays(): void
    {
        $this->refreshSeoIntel();
    }

    public function updatedGscDevice(): void
    {
        $this->refreshSeoIntel();
    }

    public function updatedGscCountry(): void
    {
        $this->refreshSeoIntel();
    }

    public function updatedGscLocale(): void
    {
        $this->refreshSeoIntel();
    }

    public function focusIssue(string $issue): void
    {
        $this->activeWorkspace = 'execution';
        $this->issueFilter = $issue;
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function applySavedView(string $view): void
    {
        $this->savedView = $view;

        if ($view === 'high_impressions_low_ctr') {
            $this->activeWorkspace = 'opportunities';
        } elseif ($view === 'current_org_blockers') {
            $this->activeWorkspace = 'execution';
            $this->scopeFilter = 'current_org';
            $this->issueFilter = SeoOperationsService::ISSUE_GROWTH;
        } elseif ($view === 'global_career_gaps') {
            $this->activeWorkspace = 'execution';
            $this->scopeFilter = 'global';
            $this->issueFilter = 'all';
        } else {
            $this->activeWorkspace = 'overview';
            $this->scopeFilter = 'combined';
            $this->issueFilter = 'all';
        }

        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function applyIssueWorkflow(SeoIssueWorkflowService $workflow, AuditLogger $audit): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }

        $user = auth((string) config('admin.guard', 'admin'))->user();
        $owner = trim((string) data_get($user, 'name', 'operator'));
        $result = $workflow->transition(
            $this->selectedIssueUid,
            $this->workflowAction,
            $owner,
            $this->ignoreReason,
            trim($this->ignoredUntil) !== '' ? $this->ignoredUntil : null,
        );

        $audit->log(request(), 'seo_issue_workflow_transition', 'SeoIssue', null, [
            'issue_uid' => $result['issue_uid'],
            'action' => $result['action'],
            'status' => $result['status'],
        ]);

        $this->ignoreReason = '';
        $this->ignoredUntil = '';
        $this->refreshSeoIntel();

        Notification::make()
            ->title(__('ops.custom_pages.seo_operations.notifications.workflow_applied'))
            ->success()
            ->send();
    }

    public function applyBulkAction(SeoOperationsService $service, AuditLogger $audit): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }

        if ($this->selectedTargets === []) {
            Notification::make()
                ->title(__('ops.custom_pages.seo_operations.notifications.select_issue'))
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
            ->title(__('ops.custom_pages.seo_operations.notifications.applied'))
            ->body(__('ops.custom_pages.seo_operations.notifications.applied_body', ['count' => $updatedCount]))
            ->success()
            ->send();
    }

    public function exportReport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! ContentAccess::canRead()) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }

        // Recompute from the authoritative query so the export always reflects
        // the current org context and never a stale snapshot.
        $this->refreshDashboard(app(SeoOperationsService::class));

        $filename = 'seo-operations-'.now()->format('Y-m-d-H-i-s').'.csv';

        $callback = function (): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map($this->spreadsheetSafeCell(...), ['section', 'label', 'value', 'suffix', 'tone']));

            foreach (['healthBand' => $this->healthBand, 'headlineFields' => $this->headlineFields, 'coverageFields' => $this->coverageFields, 'growthFields' => $this->growthFields] as $section => $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                        $section,
                        (string) ($row['label'] ?? ''),
                        (string) ($row['value'] ?? ''),
                        (string) ($row['suffix'] ?? ''),
                        (string) ($row['tone'] ?? ''),
                    ]));
                }
            }

            fputcsv($out, []);
            fputcsv($out, array_map($this->spreadsheetSafeCell(...), ['issue_queue', 'title', 'type', 'status', 'scope', 'issues', 'growth_signal']));

            foreach ($this->issueQueue as $item) {
                fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                    'issue_queue',
                    (string) ($item['title'] ?? ''),
                    (string) ($item['type'] ?? ''),
                    (string) ($item['status'] ?? ''),
                    (string) ($item['scope'] ?? ''),
                    implode('; ', (array) ($item['issue_labels'] ?? [])),
                    (string) ($item['growth_signal'] ?? ''),
                ]));
            }

            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function spreadsheetSafeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^(?:\s*[=+\-@]|[\t\r\n])/u', $value) === 1
            ? "'".$value
            : $value;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.seo_operations');
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.seo_operations.title');
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
            ->limit(self::MAX_RECORDS_PER_CONTENT_TYPE)
            ->get();
        /** @var Collection<int, CareerGuide> $guides */
        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with('seoMeta')
            ->latest('updated_at')
            ->limit(self::MAX_RECORDS_PER_CONTENT_TYPE)
            ->get();
        /** @var Collection<int, CareerJob> $jobs */
        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with('seoMeta')
            ->latest('updated_at')
            ->limit(self::MAX_RECORDS_PER_CONTENT_TYPE)
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
                'label' => __('ops.custom_pages.seo_operations.fields.article_ready'),
                'value' => $this->ratioLabel($articleSeoReady, $articleTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.article_ready_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.career_ready'),
                'value' => $this->ratioLabel($careerSeoReady, $careerTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.career_ready_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.indexable_footprint'),
                'value' => (string) $indexableFootprint,
                'hint' => __('ops.custom_pages.seo_operations.fields.indexable_footprint_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.growth_ready'),
                'value' => (string) $publicSeoReady,
                'hint' => __('ops.custom_pages.seo_operations.fields.growth_ready_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.attention_queue'),
                'value' => (string) $seoAttentionQueue,
                'hint' => __('ops.custom_pages.seo_operations.fields.attention_queue_hint'),
            ],
        ];

        $this->coverageFields = [
            [
                'label' => __('ops.custom_pages.seo_operations.fields.article_canonical'),
                'value' => $this->ratioLabel($articleCanonicalCoverage, $articleTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.article_canonical_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.article_social'),
                'value' => $this->ratioLabel($articleSocialCoverage, $articleTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.article_social_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.guide_canonical'),
                'value' => $this->ratioLabel($guideCanonicalCoverage, $guideTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.guide_canonical_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.job_canonical'),
                'value' => $this->ratioLabel($jobCanonicalCoverage, $jobTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.job_canonical_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.robots_gaps'),
                'value' => (string) $robotsGaps,
                'kind' => 'pill',
                'state' => $robotsGaps > 0 ? 'warning' : 'success',
                'hint' => __('ops.custom_pages.seo_operations.fields.robots_gaps_hint'),
            ],
        ];

        $this->growthFields = [
            [
                'label' => __('ops.custom_pages.seo_operations.fields.published_blockers'),
                'value' => (string) $publishedDiscoveryBlocked,
                'hint' => __('ops.custom_pages.seo_operations.fields.published_blockers_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.social_blockers'),
                'value' => (string) $socialPreviewBlocked,
                'hint' => __('ops.custom_pages.seo_operations.fields.social_blockers_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.noindex_inventory'),
                'value' => (string) $noindexInventory,
                'hint' => __('ops.custom_pages.seo_operations.fields.noindex_inventory_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.fields.growth_ratio'),
                'value' => $this->ratioLabel($publicSeoReady, $articleTotal + $careerTotal),
                'hint' => __('ops.custom_pages.seo_operations.fields.growth_ratio_hint'),
            ],
        ];

        $this->attentionCards = [
            $this->attentionCard(
                __('ops.custom_pages.seo_operations.fields.article_gaps'),
                __('ops.custom_pages.seo_operations.fields.article_gaps_desc'),
                $articleTotal - $articleSeoReady,
                __('ops.custom_pages.editorial_operations.surfaces.current_org'),
                $this->latestIssueTitle($service, 'article', $articles)
            ),
            $this->attentionCard(
                __('ops.custom_pages.seo_operations.fields.guide_gaps'),
                __('ops.custom_pages.seo_operations.fields.guide_gaps_desc'),
                $guideTotal - $guideSeoReady,
                __('ops.custom_pages.common.values.global_content'),
                $this->latestIssueTitle($service, 'guide', $guides)
            ),
            $this->attentionCard(
                __('ops.custom_pages.seo_operations.fields.job_gaps'),
                __('ops.custom_pages.seo_operations.fields.job_gaps_desc'),
                $jobTotal - $jobSeoReady,
                __('ops.custom_pages.common.values.global_content'),
                $this->latestIssueTitle($service, 'job', $jobs)
            ),
            [
                'title' => __('ops.custom_pages.seo_operations.fields.growth_blockers'),
                'description' => __('ops.custom_pages.seo_operations.fields.growth_blockers_desc'),
                'meta' => __('ops.custom_pages.seo_operations.fields.growth_blockers_meta', ['count' => $publishedDiscoveryBlocked]),
                'value' => (string) $publishedDiscoveryBlocked,
                'status' => $publishedDiscoveryBlocked > 0 ? __('ops.custom_pages.common.values.needs_attention') : __('ops.custom_pages.common.values.healthy'),
                'status_state' => $publishedDiscoveryBlocked > 0 ? 'warning' : 'success',
                'latest_title' => $this->latestGrowthBlockedTitle($service, $articles, $guides, $jobs),
            ],
        ];

        $issueQueue = $service->buildIssueQueue($currentOrgIds, $this->typeFilter, $this->issueFilter);
        $this->issueQueue = $issueQueue['items'] ?? [];
        if ($this->scopeFilter === 'current_org') {
            $this->issueQueue = array_values(array_filter($this->issueQueue, static fn (array $item): bool => ($item['type'] ?? null) === 'article'));
        } elseif ($this->scopeFilter === 'global') {
            $this->issueQueue = array_values(array_filter($this->issueQueue, static fn (array $item): bool => in_array(($item['type'] ?? null), ['guide', 'job'], true)));
        }
        $this->issueQueueElapsedMs = (int) ($issueQueue['elapsed_ms'] ?? 0);

        $this->issueBreakdown = $this->buildIssueBreakdown($this->issueQueue);

        $totalInventory = $articleTotal + $careerTotal;
        $totalSeoReady = $articleSeoReady + $careerSeoReady;
        $seoReadinessPct = $totalInventory > 0 ? (int) round(($totalSeoReady / $totalInventory) * 100) : 0;
        $indexableInventory = $indexableFootprint;

        $this->healthBand = [
            [
                'label' => __('ops.custom_pages.seo_operations.health.current_org_ready'),
                'value' => (string) ($articleTotal > 0 ? (int) round(($articleSeoReady / $articleTotal) * 100) : 0),
                'suffix' => '%',
                'tone' => 'info',
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.health.global_career_ready'),
                'value' => (string) ($careerTotal > 0 ? (int) round(($careerSeoReady / $careerTotal) * 100) : 0),
                'suffix' => '%',
                'tone' => 'info',
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.health.combined_ready'),
                'value' => (string) $seoReadinessPct,
                'suffix' => '%',
                'tone' => $seoReadinessPct >= 80 ? 'success' : ($seoReadinessPct >= 60 ? 'warning' : 'danger'),
            ],
            [
                'label' => __('ops.custom_pages.seo_operations.health.indexable_inventory'),
                'value' => (string) $indexableInventory,
                'suffix' => '/'.((string) $totalInventory),
                'tone' => 'info',
            ],
        ];

        $this->scopeSummary = [
            ['key' => 'current_org', 'label' => __('ops.custom_pages.seo_operations.scopes.current_org_articles'), 'count' => $articleTotal],
            ['key' => 'global', 'label' => __('ops.custom_pages.seo_operations.scopes.global_career'), 'count' => $careerTotal],
            ['key' => 'combined', 'label' => __('ops.custom_pages.seo_operations.scopes.combined'), 'count' => $totalInventory],
        ];
    }

    private function refreshSeoIntel(): void
    {
        try {
            $reader = app(SeoDashboardApiReadService::class);
            $this->searchPerformance = $reader->searchPerformance([
                'days' => $this->gscDays,
                'device' => $this->gscDevice,
                'country' => $this->gscCountry,
                'locale' => $this->gscLocale,
            ]);
            $this->opportunityQueue = (array) data_get($reader->opportunityQueue(25), 'recent_rows', []);
            $this->executionQueue = (array) data_get($reader->issues(50), 'recent_rows', []);
            $this->seoIntelAvailable = true;
        } catch (Throwable) {
            $this->searchPerformance = ['connected' => false, 'totals' => [], 'daily' => [], 'query_page_rows' => []];
            $this->opportunityQueue = [];
            $this->executionQueue = [];
            $this->seoIntelAvailable = false;
        }

        $gscConnected = (bool) ($this->searchPerformance['connected'] ?? false);
        $this->dataSources = [
            ['key' => 'cms', 'label' => 'CMS / SEO metadata', 'connected' => true, 'source' => 'primary database', 'updated_at' => now()->toAtomString()],
            ['key' => 'gsc', 'label' => 'Google Search Console', 'connected' => $gscConnected, 'source' => $gscConnected ? 'seo_intel.seo_gsc_daily' : null, 'updated_at' => $this->searchPerformance['updated_at'] ?? null],
            ['key' => 'cwv', 'label' => 'Core Web Vitals', 'connected' => false, 'phase' => 'Phase 2'],
            ['key' => 'rank', 'label' => __('ops.custom_pages.seo_operations.sources.rank_tracking'), 'connected' => false, 'phase' => 'Phase 2'],
            ['key' => 'ai', 'label' => 'AI Visibility', 'connected' => false, 'phase' => 'Phase 2'],
            ['key' => 'backlinks', 'label' => __('ops.custom_pages.seo_operations.sources.backlinks'), 'connected' => false, 'phase' => 'Phase 2'],
        ];

        try {
            $this->deploymentEvents = OpsDeployEvent::query()
                ->where('occurred_at', '>=', now()->subDays($this->gscDays - 1))
                ->latest('occurred_at')
                ->limit(20)
                ->get(['revision', 'status', 'env', 'occurred_at'])
                ->map(static fn (OpsDeployEvent $event): array => [
                    'revision' => (string) $event->revision,
                    'status' => (string) $event->status,
                    'environment' => (string) $event->env,
                    'occurred_at' => optional($event->occurred_at)->toAtomString(),
                ])
                ->all();
        } catch (Throwable) {
            $this->deploymentEvents = [];
        }
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
        return $records->filter(function (object $record) use ($service, $type): bool {
            $expectedCanonical = $service->expectedCanonical($type, $record);

            return $expectedCanonical !== null
                && trim((string) data_get($record, 'seoMeta.canonical_url', '')) === $expectedCanonical;
        })->count();
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
        return $records->filter(fn (object $record): bool => $service->hasPublishedDiscoveryBlocker($type, $record))->count();
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
            $articles->first(fn (Article $record): bool => $service->hasPublishedDiscoveryBlocker('article', $record)),
            $guides->first(fn (CareerGuide $record): bool => $service->hasPublishedDiscoveryBlocker('guide', $record)),
            $jobs->first(fn (CareerJob $record): bool => $service->hasPublishedDiscoveryBlocker('job', $record)),
        ])->filter(fn ($record): bool => is_object($record));

        /** @var object|null $latest */
        $latest = $candidates->sortByDesc(static fn (object $record): string => (string) optional(data_get($record, 'updated_at'))->toISOString())->first();

        return trim((string) data_get($latest, 'title', '')) !== '' ? trim((string) data_get($latest, 'title', '')) : __('ops.custom_pages.common.values.no_recent_record');
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
     * Aggregate issue labels across the current queue into a per-category
     * breakdown. Fully derived from real queue data — no synthetic values.
     *
     * @param  list<array<string, mixed>>  $queue
     * @return list<array<string, mixed>>
     */
    private function buildIssueBreakdown(array $queue): array
    {
        $counts = [];
        $total = 0;

        foreach ($queue as $item) {
            foreach (($item['issue_labels'] ?? []) as $index => $label) {
                $code = (string) data_get($item, 'issue_codes.'.$index, 'all');
                $key = $code.'|'.$label;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $total++;
            }
        }

        if ($total === 0) {
            return [];
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            [$code, $label] = explode('|', $key, 2);
            $rows[] = [
                'code' => $code,
                'label' => $label,
                'count' => $count,
                'pct' => (int) round(($count / $total) * 100),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
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
            'meta' => __('ops.custom_pages.seo_operations.fields.records_need_work', ['scope' => $scope, 'count' => $count]),
            'value' => (string) $count,
            'status' => $count > 0 ? __('ops.custom_pages.common.values.needs_attention') : __('ops.custom_pages.common.values.healthy'),
            'status_state' => $count > 0 ? 'warning' : 'success',
            'latest_title' => trim((string) $latestTitle) !== '' ? trim((string) $latestTitle) : __('ops.custom_pages.common.values.no_recent_record'),
        ];
    }
}
