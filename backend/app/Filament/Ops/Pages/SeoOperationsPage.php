<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\OpsDeployEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\SeoContentScopeViewModel;
use App\Services\Ops\SeoOperationsService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueWorkflowService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Throwable;

class SeoOperationsPage extends Page
{
    public const ISSUE_QUEUE_PER_PAGE = 25;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'seo-operations';

    protected static string $view = 'filament.ops.pages.seo-operations';

    public string $typeFilter = 'all';

    public string $issueFilter = 'all';

    public string $localeFilter = 'all';

    public string $statusFilter = 'all';

    public string $scopeFilter = 'combined';

    public string $activeWorkspace = 'overview';

    public string $savedView = 'all';

    public int $issueQueuePage = 1;

    public int $gscDays = 28;

    public string $gscDevice = 'all';

    public string $gscCountry = 'all';

    public string $gscLocale = 'all';

    public string $gscSearchType = 'all';

    public string $selectedIssueUid = '';

    public int $selectedLockVersion = -1;

    public string $workflowAction = SeoIssueWorkflowService::ACTION_ASSIGN;

    public string $ignoreReason = '';

    public string $ignoredUntil = '';

    public string $operatorNote = '';

    public string $verificationNote = '';

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

    /** @var array<string, mixed> */
    public array $opportunityReadModel = [];

    /** @var array<string, mixed> */
    public array $technicalAudit = [];

    /** @var list<array<string, mixed>> */
    public array $issueClusters = [];

    public int $issueClusterPage = 1;

    public int $issueClusterTotal = 0;

    public int $issueClusterLastPage = 1;

    public string $selectedClusterUid = '';

    /** @var list<array<string, mixed>> */
    public array $clusterUrls = [];

    public int $clusterUrlPage = 1;

    public int $clusterUrlTotal = 0;

    public int $clusterUrlLastPage = 1;

    /** @var list<array<string, mixed>> */
    public array $dataSources = [];

    /** @var array<string, int> */
    public array $issueClusterSummary = [];

    /** @var list<array<string, mixed>> */
    public array $decisionSignals = [];

    /** @var list<array<string, mixed>> */
    public array $criticalAnomalies = [];

    /** @var list<array<string, mixed>> */
    public array $overviewPriorityClusters = [];

    /** @var list<array<string, mixed>> */
    public array $todayActions = [];

    /** @var list<array<string, mixed>> */
    public array $scopeSummary = [];

    /** @var list<array<string, mixed>> */
    public array $deploymentEvents = [];

    public function mount(SeoOperationsService $service): void
    {
        $this->reopenExpiredIgnores();
        $this->refreshDashboard($service);
        $this->refreshSeoIntel();
    }

    public function updatedSelectedIssueUid(): void
    {
        $this->syncSelectedIssueVersion();
    }

    public function updatedTypeFilter(): void
    {
        $this->issueQueuePage = 1;
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedIssueFilter(): void
    {
        $this->issueQueuePage = 1;
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedLocaleFilter(): void
    {
        $this->issueQueuePage = 1;
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedStatusFilter(): void
    {
        $this->issueQueuePage = 1;
        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    public function updatedScopeFilter(): void
    {
        $this->issueQueuePage = 1;
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

    public function updatedGscSearchType(): void
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
        $this->issueQueuePage = 1;
        $this->savedView = $view;

        if ($view === 'high_impressions_low_ctr') {
            $this->activeWorkspace = 'opportunities';
        } elseif ($view === 'global_article_blockers') {
            $this->activeWorkspace = 'execution';
            $this->scopeFilter = SeoContentScopeViewModel::SCOPE_GLOBAL_ARTICLES;
            $this->issueFilter = SeoOperationsService::ISSUE_GROWTH;
        } elseif ($view === 'global_career_gaps') {
            $this->activeWorkspace = 'execution';
            $this->scopeFilter = 'global';
            $this->issueFilter = 'all';
        } else {
            $this->activeWorkspace = 'overview';
            $this->scopeFilter = SeoContentScopeViewModel::SCOPE_COMBINED;
            $this->issueFilter = 'all';
        }

        $this->selectedTargets = [];
        $this->refreshDashboard(app(SeoOperationsService::class));
    }

    /** @return list<array<string, mixed>> */
    public function visibleIssueQueue(): array
    {
        $lastPage = max(1, (int) ceil(count($this->issueQueue) / self::ISSUE_QUEUE_PER_PAGE));
        $page = min(max(1, $this->issueQueuePage), $lastPage);

        return array_slice(
            $this->issueQueue,
            ($page - 1) * self::ISSUE_QUEUE_PER_PAGE,
            self::ISSUE_QUEUE_PER_PAGE,
        );
    }

    public function previousIssueQueuePage(): void
    {
        $this->issueQueuePage = max(1, $this->issueQueuePage - 1);
    }

    public function nextIssueQueuePage(): void
    {
        $lastPage = max(1, (int) ceil(count($this->issueQueue) / self::ISSUE_QUEUE_PER_PAGE));
        $this->issueQueuePage = min($lastPage, $this->issueQueuePage + 1);
    }

    public function inspectIssueCluster(string $clusterUid): void
    {
        $this->selectedClusterUid = $clusterUid;
        $this->clusterUrlPage = 1;
        $this->refreshClusterUrls(app(SeoDashboardApiReadService::class));
    }

    public function previousIssueClusterPage(): void
    {
        $this->issueClusterPage = max(1, $this->issueClusterPage - 1);
        $this->refreshIssueClusters(app(SeoDashboardApiReadService::class));
    }

    public function nextIssueClusterPage(): void
    {
        $this->issueClusterPage = min($this->issueClusterLastPage, $this->issueClusterPage + 1);
        $this->refreshIssueClusters(app(SeoDashboardApiReadService::class));
    }

    public function previousClusterUrlPage(): void
    {
        $this->clusterUrlPage = max(1, $this->clusterUrlPage - 1);
        $this->refreshClusterUrls(app(SeoDashboardApiReadService::class));
    }

    public function nextClusterUrlPage(): void
    {
        $this->clusterUrlPage = min($this->clusterUrlLastPage, $this->clusterUrlPage + 1);
        $this->refreshClusterUrls(app(SeoDashboardApiReadService::class));
    }

    public function applyIssueWorkflow(SeoIssueWorkflowService $workflow, AuditLogger $audit): void
    {
        if (! ContentAccess::canWrite()) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }

        $user = auth((string) config('admin.guard', 'admin'))->user();
        if (! $user instanceof AdminUser) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }
        $result = $workflow->transition(
            $this->selectedIssueUid,
            $this->workflowAction,
            $user,
            $this->selectedLockVersion,
            $this->operatorNote,
            $this->ignoreReason,
            trim($this->ignoredUntil) !== '' ? $this->ignoredUntil : null,
            $this->verificationNote,
        );

        $audit->log(request(), 'seo_issue_workflow_transition', 'SeoIssue', (string) $result['issue_uid'], $result);

        $this->operatorNote = '';
        $this->ignoreReason = '';
        $this->ignoredUntil = '';
        $this->verificationNote = '';
        $this->refreshSeoIntel();
        $this->syncSelectedIssueVersion();

        Notification::make()
            ->title(__('ops.custom_pages.seo_operations.notifications.workflow_applied'))
            ->success()
            ->send();
    }

    private function reopenExpiredIgnores(): void
    {
        if (! ContentAccess::canWrite()) {
            return;
        }

        $user = auth((string) config('admin.guard', 'admin'))->user();
        if (! $user instanceof AdminUser) {
            throw new AuthorizationException(__('ops.custom_pages.common.errors.seo_action_forbidden'));
        }

        $audit = app(AuditLogger::class);
        try {
            $results = app(SeoIssueWorkflowService::class)->reopenExpiredIgnores($user);
        } catch (\Illuminate\Database\QueryException|\InvalidArgumentException) {
            // The CMS fallback remains usable when seo_intel is unavailable.
            // No expiry state is changed without a successful protected read.
            return;
        }

        foreach ($results as $result) {
            $audit->log(request(), 'seo_issue_ignore_expired_reopen', 'SeoIssue', (string) $result['issue_uid'], $result);
        }
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

        $result = $service->applyBulkAction($this->selectedTargets, $this->bulkAction, $this->seoAuthorityOrgIds());
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
        [$clusterExport, $clusterUrlExport] = $this->fullClusterExport();

        $callback = function () use ($clusterExport, $clusterUrlExport): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                'section', 'label', 'value', 'suffix', 'tone', 'scope', 'source', 'collected_at', 'source_updated_at', 'freshness', 'locale_filter', 'status_filter',
            ]));

            foreach (['healthBand' => $this->healthBand, 'headlineFields' => $this->headlineFields, 'coverageFields' => $this->coverageFields, 'growthFields' => $this->growthFields] as $section => $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                        $section,
                        (string) ($row['label'] ?? ''),
                        (string) ($row['value'] ?? ''),
                        (string) ($row['suffix'] ?? ''),
                        (string) ($row['tone'] ?? ''),
                        (string) ($row['scope'] ?? ''),
                        (string) ($row['source'] ?? ''),
                        (string) ($row['collected_at'] ?? ''),
                        (string) ($row['source_updated_at'] ?? ''),
                        (string) ($row['freshness'] ?? ''),
                        $this->localeFilter,
                        $this->statusFilter,
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

            fputcsv($out, []);
            fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                'issue_clusters', 'cluster_uid', 'root_cause', 'content_type', 'template', 'field', 'severity', 'affected_url_count', 'issue_count', 'evidence_count', 'priority_score', 'priority_impact', 'priority_confidence', 'priority_effort', 'priority_reason', 'gsc_included', 'gsc_clicks', 'gsc_impressions', 'status', 'source', 'recommendation',
            ]));
            foreach ($clusterExport as $cluster) {
                fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                    'issue_clusters',
                    (string) ($cluster['cluster_uid'] ?? ''),
                    (string) ($cluster['root_cause'] ?? ''),
                    (string) ($cluster['content_type'] ?? ''),
                    (string) ($cluster['template'] ?? ''),
                    (string) ($cluster['field'] ?? ''),
                    (string) ($cluster['severity'] ?? ''),
                    (string) ($cluster['affected_url_count'] ?? 0),
                    (string) ($cluster['issue_count'] ?? 0),
                    (string) ($cluster['evidence_count'] ?? 0),
                    (string) data_get($cluster, 'priority.score', ''),
                    (string) data_get($cluster, 'priority.impact.total', ''),
                    (string) data_get($cluster, 'priority.confidence.value', ''),
                    (string) data_get($cluster, 'priority.effort.value', ''),
                    (string) data_get($cluster, 'priority.sort_reason', ''),
                    data_get($cluster, 'priority.impact.gsc.included', false) ? 'true' : 'false',
                    (string) data_get($cluster, 'priority.impact.gsc.clicks', 0),
                    (string) data_get($cluster, 'priority.impact.gsc.impressions', 0),
                    (string) ($cluster['status'] ?? ''),
                    (string) ($cluster['source'] ?? ''),
                    (string) ($cluster['recommendation'] ?? ''),
                ]));
            }

            fputcsv($out, []);
            fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                'cluster_urls', 'cluster_uid', 'issue_uid', 'canonical_path', 'locale', 'page_entity_type', 'severity', 'status', 'source', 'evidence_fingerprint',
            ]));
            foreach ($clusterUrlExport as $clusterUid => $urls) {
                foreach ($urls as $url) {
                    fputcsv($out, array_map($this->spreadsheetSafeCell(...), [
                        'cluster_urls',
                        $clusterUid,
                        (string) ($url['issue_uid'] ?? ''),
                        (string) ($url['canonical_path'] ?? ''),
                        (string) ($url['locale'] ?? ''),
                        (string) ($url['page_entity_type'] ?? ''),
                        (string) ($url['severity'] ?? ''),
                        (string) ($url['status'] ?? ''),
                        (string) ($url['source'] ?? ''),
                        (string) ($url['evidence_fingerprint'] ?? ''),
                    ]));
                }
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
    private function seoAuthorityOrgIds(): array
    {
        return [SeoContentScopeViewModel::GLOBAL_ORG_ID];
    }

    private function refreshDashboard(SeoOperationsService $service): void
    {
        $seoAuthorityOrgIds = $this->seoAuthorityOrgIds();
        $scope = app(SeoContentScopeViewModel::class);
        $inventory = $scope->inventory($this->localeFilter, $this->statusFilter);

        /** @var Collection<int, Article> $articles */
        $articles = $inventory['articles'];
        /** @var Collection<int, CareerGuide> $guides */
        $guides = $inventory['guides'];
        /** @var Collection<int, CareerJob> $jobs */
        $jobs = $inventory['jobs'];

        $collectedAt = now();
        $articleContract = $scope->metricContract(
            SeoContentScopeViewModel::SCOPE_GLOBAL_ARTICLES,
            SeoContentScopeViewModel::SOURCE_ARTICLES,
            $articles,
            $collectedAt,
        );
        $careerRecords = $guides->concat($jobs);
        $careerContract = $scope->metricContract(
            SeoContentScopeViewModel::SCOPE_GLOBAL_CAREER,
            SeoContentScopeViewModel::SOURCE_CAREER_GUIDES.','.SeoContentScopeViewModel::SOURCE_CAREER_JOBS,
            $careerRecords,
            $collectedAt,
        );
        $combinedContract = $scope->metricContract(
            SeoContentScopeViewModel::SCOPE_COMBINED,
            SeoContentScopeViewModel::SOURCE_ARTICLES.','.SeoContentScopeViewModel::SOURCE_CAREER_GUIDES.','.SeoContentScopeViewModel::SOURCE_CAREER_JOBS,
            $articles->concat($careerRecords),
            $collectedAt,
        );

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
        $this->headlineFields = $this->annotateMetrics($this->headlineFields, [
            $articleContract, $careerContract, $combinedContract, $combinedContract, $combinedContract,
        ]);

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
        $this->coverageFields = $this->annotateMetrics($this->coverageFields, [
            $articleContract, $articleContract, $careerContract, $careerContract, $combinedContract,
        ]);

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
        $this->growthFields = $this->annotateMetrics($this->growthFields, array_fill(0, count($this->growthFields), $combinedContract));

        $issueQueue = $service->buildIssueQueue(
            $seoAuthorityOrgIds,
            $this->typeFilter,
            $this->issueFilter,
            $this->localeFilter,
            $this->statusFilter,
            null,
        );
        $this->issueQueue = $issueQueue['items'] ?? [];
        if ($this->scopeFilter === SeoContentScopeViewModel::SCOPE_GLOBAL_ARTICLES) {
            $this->issueQueue = array_values(array_filter($this->issueQueue, static fn (array $item): bool => ($item['type'] ?? null) === 'article'));
        } elseif ($this->scopeFilter === SeoContentScopeViewModel::SCOPE_GLOBAL_CAREER) {
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
        $this->healthBand = $this->annotateMetrics($this->healthBand, [
            $articleContract, $careerContract, $combinedContract, $combinedContract,
        ]);

        $this->scopeSummary = [
            array_merge(['key' => SeoContentScopeViewModel::SCOPE_GLOBAL_ARTICLES, 'label' => __('ops.custom_pages.seo_operations.scopes.global_articles'), 'count' => $articleTotal], $articleContract),
            array_merge(['key' => SeoContentScopeViewModel::SCOPE_GLOBAL_CAREER, 'label' => __('ops.custom_pages.seo_operations.scopes.global_career'), 'count' => $careerTotal], $careerContract),
            array_merge(['key' => SeoContentScopeViewModel::SCOPE_COMBINED, 'label' => __('ops.custom_pages.seo_operations.scopes.combined'), 'count' => $totalInventory], $combinedContract),
        ];

        $this->refreshDecisionOverview();
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $contracts
     * @return list<array<string,mixed>>
     */
    private function annotateMetrics(array $rows, array $contracts): array
    {
        return array_values(array_map(
            static fn (array $row, int $index): array => array_merge($row, $contracts[$index] ?? []),
            $rows,
            array_keys($rows),
        ));
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
                'search_type' => $this->gscSearchType,
            ]);
            $this->opportunityReadModel = $reader->opportunityQueue(25);
            $this->opportunityQueue = (array) data_get($this->opportunityReadModel, 'recent_rows', []);
            $this->technicalAudit = $reader->technicalAudits(25);
            $this->refreshIssueClusters($reader);
            if ($this->selectedClusterUid !== '') {
                $this->refreshClusterUrls($reader);
            }
            $this->seoIntelAvailable = true;
        } catch (Throwable) {
            $this->searchPerformance = ['connected' => false, 'state' => 'unavailable', 'totals' => [], 'daily' => [], 'query_page_rows' => []];
            $this->opportunityQueue = [];
            $this->opportunityReadModel = ['state' => 'unavailable', 'recent_rows' => []];
            $this->technicalAudit = ['state' => 'unavailable', 'rows' => [], 'sources' => []];
            $this->issueClusters = [];
            $this->issueClusterSummary = [];
            $this->issueClusterTotal = 0;
            $this->issueClusterLastPage = 1;
            $this->clusterUrls = [];
            $this->clusterUrlTotal = 0;
            $this->clusterUrlLastPage = 1;
            $this->seoIntelAvailable = false;
        }

        $gscConnected = (bool) ($this->searchPerformance['source_connected'] ?? $this->searchPerformance['connected'] ?? false);
        $this->dataSources = [
            ['key' => 'cms', 'label' => __('ops.custom_pages.seo_operations.sources.cms'), 'connected' => true, 'source' => 'primary database', 'updated_at' => now()->toAtomString()],
            ['key' => 'gsc', 'label' => __('ops.custom_pages.seo_operations.sources.gsc'), 'connected' => $gscConnected, 'state' => $this->searchPerformance['state'] ?? 'disconnected', 'source' => ($this->searchPerformance['data_available'] ?? false) ? 'seo_intel.seo_gsc_daily' : null, 'updated_at' => $this->searchPerformance['last_success_at'] ?? $this->searchPerformance['updated_at'] ?? null],
            ['key' => 'cwv', 'label' => __('ops.custom_pages.seo_operations.sources.cwv'), 'connected' => false, 'phase' => __('ops.custom_pages.seo_operations.phase_two')],
            ['key' => 'rank', 'label' => __('ops.custom_pages.seo_operations.sources.rank_tracking'), 'connected' => false, 'phase' => __('ops.custom_pages.seo_operations.phase_two')],
            ['key' => 'ai', 'label' => __('ops.custom_pages.seo_operations.workspace.ai'), 'connected' => false, 'phase' => __('ops.custom_pages.seo_operations.phase_two')],
            ['key' => 'backlinks', 'label' => __('ops.custom_pages.seo_operations.sources.backlinks'), 'connected' => false, 'phase' => __('ops.custom_pages.seo_operations.phase_two')],
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

        $this->refreshDecisionOverview();
    }

    private function refreshIssueClusters(SeoDashboardApiReadService $reader): void
    {
        $result = $reader->issueClusters(page: $this->issueClusterPage, perPage: self::ISSUE_QUEUE_PER_PAGE);
        $this->issueClusters = (array) ($result['rows'] ?? []);
        $this->issueClusterSummary = (array) ($result['summary'] ?? []);
        $this->issueClusterTotal = (int) ($result['total_count'] ?? 0);
        $this->issueClusterPage = (int) ($result['page'] ?? 1);
        $this->issueClusterLastPage = (int) ($result['last_page'] ?? 1);
    }

    private function refreshDecisionOverview(): void
    {
        $signals = [];
        $searchChange = $this->searchChangeSignal();
        if ($searchChange !== null) {
            $signals[] = $searchChange;
        }

        $affectedUrls = $this->seoIntelAvailable
            ? (int) ($this->issueClusterSummary['affected_url_count'] ?? 0)
            : count($this->issueQueue);
        $indexBlockers = $this->seoIntelAvailable
            ? (int) ($this->issueClusterSummary['index_blocker_url_count'] ?? 0)
            : count(array_filter($this->issueQueue, static fn (array $row): bool => array_intersect(
                (array) ($row['issue_codes'] ?? []),
                ['canonical', 'robots', 'indexability', 'growth'],
            ) !== []));

        $signals[] = $this->decisionSignal('affected_urls', $affectedUrls, 'execution');
        $signals[] = $this->decisionSignal('index_blockers', $indexBlockers, 'technical');

        if ($this->seoIntelAvailable) {
            $signals[] = $this->decisionSignal(
                'high_priority_issues',
                (int) ($this->issueClusterSummary['high_priority_cluster_count'] ?? 0),
                'execution',
            );
            $signals[] = $this->decisionSignal(
                'overdue_tasks',
                (int) ($this->issueClusterSummary['overdue_task_count'] ?? 0),
                'execution',
            );
        }

        $this->decisionSignals = array_slice($signals, 0, 5);
        $this->criticalAnomalies = array_values(array_slice(array_filter(
            $this->issueClusters,
            static fn (array $cluster): bool => ($cluster['status'] ?? null) === 'open'
                && in_array(($cluster['severity'] ?? null), ['high', 'critical'], true),
        ), 0, 3));
        $this->overviewPriorityClusters = array_values(array_slice(array_filter(
            $this->issueClusters,
            static fn (array $cluster): bool => ($cluster['status'] ?? null) === 'open'
                && (bool) data_get($cluster, 'priority.ranking_eligible', false),
        ), 0, 3));
        $this->todayActions = array_map(static fn (array $cluster): array => [
            'cluster_uid' => (string) $cluster['cluster_uid'],
            'edit_url' => null,
            'title' => (string) $cluster['issue_type'],
            'reason' => (string) ($cluster['summary'] ?? $cluster['root_cause'] ?? '-'),
            'impact' => (int) ($cluster['affected_url_count'] ?? 0),
            'score' => data_get($cluster, 'priority.score', '-'),
            'action' => (string) ($cluster['recommendation'] ?? '-'),
        ], array_slice($this->overviewPriorityClusters, 0, 5));

        if ($this->todayActions === []) {
            $this->todayActions = array_map(static fn (array $row): array => [
                'cluster_uid' => null,
                'edit_url' => (string) ($row['edit_url'] ?? '#'),
                'title' => (string) ($row['title'] ?? '-'),
                'reason' => implode(' · ', (array) ($row['issue_labels'] ?? [])),
                'impact' => 1,
                'score' => '-',
                'action' => implode(' · ', array_map(
                    static fn (string $action): string => __('ops.custom_pages.seo_operations.filters.'.$action),
                    (array) ($row['autofix_actions'] ?? []),
                )),
            ], array_slice($this->issueQueue, 0, 5));
        }
    }

    /** @return array<string,mixed>|null */
    private function searchChangeSignal(): ?array
    {
        if (! (bool) ($this->searchPerformance['connected'] ?? false)) {
            return null;
        }

        $daily = collect((array) ($this->searchPerformance['daily'] ?? []))
            ->sortBy('report_date')
            ->values();
        if ($daily->count() < 2) {
            return null;
        }

        $current = (array) $daily->last();
        $previous = (array) $daily->get($daily->count() - 2);
        $currentClicks = (int) ($current['clicks'] ?? 0);
        $previousClicks = (int) ($previous['clicks'] ?? 0);
        $delta = $previousClicks > 0
            ? round((($currentClicks - $previousClicks) / $previousClicks) * 100, 1)
            : null;

        return [
            'key' => 'search_change',
            'label' => __('ops.custom_pages.seo_operations.decision.signals.search_change'),
            'value' => $delta === null ? (string) ($currentClicks - $previousClicks) : sprintf('%+.1f%%', $delta),
            'hint' => __('ops.custom_pages.seo_operations.decision.search_change_hint', [
                'current' => $currentClicks,
                'previous' => $previousClicks,
            ]),
            'workspace' => 'performance',
            'tone' => ($delta ?? ($currentClicks - $previousClicks)) < 0 ? 'danger' : 'success',
        ];
    }

    /** @return array<string,mixed> */
    private function decisionSignal(string $key, int $value, string $workspace): array
    {
        return [
            'key' => $key,
            'label' => __('ops.custom_pages.seo_operations.decision.signals.'.$key),
            'value' => (string) $value,
            'hint' => __('ops.custom_pages.seo_operations.decision.signal_hints.'.$key),
            'workspace' => $workspace,
            'tone' => $value > 0 ? 'warning' : 'success',
        ];
    }

    public function openDecisionWorkspace(string $workspace): void
    {
        if (in_array($workspace, ['overview', 'performance', 'technical', 'opportunities', 'ai', 'execution'], true)) {
            $this->activeWorkspace = $workspace;
        }
    }

    public function openClusterExecution(string $clusterUid): void
    {
        $this->activeWorkspace = 'execution';
        $this->inspectIssueCluster($clusterUid);
    }

    private function refreshClusterUrls(SeoDashboardApiReadService $reader): void
    {
        if ($this->selectedClusterUid === '') {
            $this->clusterUrls = [];
            $this->clusterUrlTotal = 0;

            return;
        }

        $result = $reader->issueClusterUrls(
            $this->selectedClusterUid,
            page: $this->clusterUrlPage,
            perPage: self::ISSUE_QUEUE_PER_PAGE,
        );
        $this->clusterUrls = (array) ($result['rows'] ?? []);
        $this->clusterUrlTotal = (int) ($result['total_count'] ?? 0);
        $this->clusterUrlPage = (int) ($result['page'] ?? 1);
        $this->clusterUrlLastPage = (int) ($result['last_page'] ?? 1);
        $this->syncSelectedIssueVersion();
    }

    private function syncSelectedIssueVersion(): void
    {
        $selected = collect($this->clusterUrls)
            ->first(fn (array $row): bool => (string) ($row['issue_uid'] ?? '') === $this->selectedIssueUid);

        $this->selectedLockVersion = is_array($selected)
            ? (int) ($selected['lock_version'] ?? -1)
            : -1;
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,list<array<string,mixed>>>} */
    private function fullClusterExport(): array
    {
        try {
            $reader = app(SeoDashboardApiReadService::class);
            $export = $reader->issueClusterExport();
            $clusters = (array) ($export['clusters'] ?? []);
            $urls = (array) ($export['urls'] ?? []);

            return [$clusters, $urls];
        } catch (Throwable) {
            return [[], []];
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
}
