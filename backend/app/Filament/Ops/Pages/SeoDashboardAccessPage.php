<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\SeoIntel\OpsDashboard\SeoCrawlerLogObservationReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardOverviewReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoSearchChannelQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoUrlTruthReadService;
use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;
use Throwable;

class SeoDashboardAccessPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'seo';

    protected static string $view = 'filament.ops.pages.seo-dashboard-access';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $dashboardSnapshot = null;

    /**
     * @return list<array{label:string,value:string,hint:string}>
     */
    public function statusCards(): array
    {
        return [
            [
                'label' => __('ops.custom_pages.seo_intelligence.cards.url_truth_rows'),
                'value' => '7',
                'hint' => __('ops.custom_pages.seo_intelligence.cards.verified_url_truth_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.cards.entity_mappings'),
                'value' => '7',
                'hint' => __('ops.custom_pages.seo_intelligence.cards.verified_entity_mappings_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.cards.issue_queue_rows'),
                'value' => '5',
                'hint' => __('ops.custom_pages.seo_intelligence.cards.verified_issue_queue_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.cards.verified_cards'),
                'value' => '10',
                'hint' => __('ops.custom_pages.seo_intelligence.cards.verified_cards_hint'),
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,hint:string}>
     */
    public function overviewCards(): array
    {
        return collect(data_get($this->dashboardSnapshot(), 'overview.heartbeat', []))
            ->map(function (array $card): array {
                $key = (string) ($card['key'] ?? '');
                $label = match ($key) {
                    'url_truth_total' => __('ops.custom_pages.seo_intelligence.cards.url_truth_rows'),
                    'url_entity_mapping_total' => __('ops.custom_pages.seo_intelligence.cards.entity_mappings'),
                    'issue_queue_total' => __('ops.custom_pages.seo_intelligence.cards.issue_queue_rows'),
                    'search_channel_queue_item_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_queue_items'),
                    'search_channel_queue_batch_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_queue_batches'),
                    'search_channel_queue_event_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_events'),
                    default => (string) ($card['label'] ?? __('ops.custom_pages.seo_intelligence.cards.metric')),
                };

                return [
                    'label' => $label,
                    'value' => (string) ((int) ($card['value'] ?? 0)),
                    'hint' => match ($key) {
                        'url_truth_total' => __('ops.custom_pages.seo_intelligence.cards.url_truth_rows_hint'),
                        'url_entity_mapping_total' => __('ops.custom_pages.seo_intelligence.cards.entity_mappings_hint'),
                        'issue_queue_total' => __('ops.custom_pages.seo_intelligence.cards.issue_queue_rows_hint'),
                        'search_channel_queue_item_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_queue_items_hint'),
                        'search_channel_queue_batch_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_queue_batches_hint'),
                        'search_channel_queue_event_total' => __('ops.custom_pages.seo_intelligence.cards.search_channel_events_hint'),
                        default => __('ops.custom_pages.seo_intelligence.cards.metric_hint'),
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label:string,value:string,hint:string,kind:string,state:string}>
     */
    public function safetyCards(): array
    {
        return collect(data_get($this->dashboardSnapshot(), 'overview.safety', []))
            ->map(function (array $card): array {
                $key = (string) ($card['key'] ?? '');

                return [
                    'label' => match ($key) {
                        'private_flow_count' => __('ops.custom_pages.seo_intelligence.cards.private_flow_leaks'),
                        'forbidden_authority_count' => __('ops.custom_pages.seo_intelligence.cards.forbidden_authority'),
                        'claim_unsafe_count' => __('ops.custom_pages.seo_intelligence.cards.claim_unsafe'),
                        default => (string) ($card['label'] ?? __('ops.custom_pages.seo_intelligence.cards.safety_counter')),
                    },
                    'value' => (string) ((int) ($card['value'] ?? 0)),
                    'hint' => ((bool) ($card['alert'] ?? false))
                        ? __('ops.custom_pages.seo_intelligence.cards.non_zero_safety_hint')
                        : __('ops.custom_pages.seo_intelligence.cards.expected_zero_hint'),
                    'kind' => 'pill',
                    'state' => ((bool) ($card['alert'] ?? false)) ? 'danger' : 'success',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{title:string,rows:list<array{label:string,count:int}>}>
     */
    public function urlTruthDistributionCards(): array
    {
        $distributions = (array) data_get($this->dashboardSnapshot(), 'url_truth.distributions', []);

        return [
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.page_entity_type'),
                'rows' => $this->distributionRows($distributions['page_entity_type'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.locale'),
                'rows' => $this->distributionRows($distributions['locale'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.source_authority'),
                'rows' => $this->distributionRows($distributions['source_authority'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.indexability_state'),
                'rows' => $this->distributionRows($distributions['indexability_state'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array{title:string,rows:list<array{label:string,count:int}>}>
     */
    public function issueQueueAggregateCards(): array
    {
        $aggregates = (array) data_get($this->dashboardSnapshot(), 'issues.aggregates', []);

        return [
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.issue_type'),
                'rows' => $this->distributionRows($aggregates['issue_type'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.severity'),
                'rows' => $this->distributionRows($aggregates['severity'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.status'),
                'rows' => $this->distributionRows($aggregates['status'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array{title:string,rows:list<array{label:string,count:int}>}>
     */
    public function searchChannelQueueAggregateCards(): array
    {
        $aggregates = (array) data_get($this->dashboardSnapshot(), 'queue.aggregates', []);

        return [
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.channel'),
                'rows' => $this->distributionRows($aggregates['channel'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.approval_state'),
                'rows' => $this->distributionRows($aggregates['approval_state'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.execution_state'),
                'rows' => $this->distributionRows($aggregates['execution_state'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array{title:string,rows:list<array{label:string,count:int}>}>
     */
    public function crawlerObservationAggregateCards(): array
    {
        $aggregates = (array) data_get($this->dashboardSnapshot(), 'crawler.aggregates', []);

        return [
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.bot_family'),
                'rows' => $this->distributionRows($aggregates['bot_family'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.surface_family'),
                'rows' => $this->distributionRows($aggregates['surface_family'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.route_family'),
                'rows' => $this->distributionRows($aggregates['route_family'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.http_status'),
                'rows' => $this->distributionRows($aggregates['http_status'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.query_risk_state'),
                'rows' => $this->distributionRows($aggregates['query_risk_state'] ?? []),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.table.private_path_blocked'),
                'rows' => $this->distributionRows($aggregates['private_path_blocked'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,hint:string,kind:string,state:string}>
     */
    public function crawlerSafetyCards(): array
    {
        $counts = (array) data_get($this->dashboardSnapshot(), 'crawler.safety_counts', []);

        return collect([
            ['key' => 'private_path_blocked_count', 'label' => __('ops.custom_pages.seo_intelligence.cards.private_paths_blocked')],
            ['key' => 'sensitive_query_count', 'label' => __('ops.custom_pages.seo_intelligence.cards.sensitive_query_risk')],
            ['key' => 'api_or_ops_surface_count', 'label' => __('ops.custom_pages.seo_intelligence.cards.api_ops_surface_hits')],
            ['key' => 'unknown_bot_count', 'label' => __('ops.custom_pages.seo_intelligence.cards.unknown_bot_family')],
        ])->map(function (array $card) use ($counts): array {
            $value = (int) ($counts[$card['key']] ?? 0);

            return [
                'label' => (string) $card['label'],
                'value' => (string) $value,
                'hint' => $value > 0
                    ? __('ops.custom_pages.seo_intelligence.cards.non_zero_crawler_hint')
                    : __('ops.custom_pages.seo_intelligence.cards.expected_zero_hint'),
                'kind' => 'pill',
                'state' => $value > 0 ? 'warning' : 'success',
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentCrawlerRows(): array
    {
        return (array) data_get($this->dashboardSnapshot(), 'crawler.recent_rows', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentIssueRows(): array
    {
        return (array) data_get($this->dashboardSnapshot(), 'issues.recent_rows', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentQueueRows(): array
    {
        return (array) data_get($this->dashboardSnapshot(), 'queue.recent_rows', []);
    }

    /**
     * @return list<array{event_type:string,count:int,latest_created_at:?string}>
     */
    public function eventTypeSummary(): array
    {
        return (array) data_get($this->dashboardSnapshot(), 'queue.aggregates.event_type', []);
    }

    public function dashboardAvailable(): bool
    {
        return (bool) data_get($this->dashboardSnapshot(), 'available', false);
    }

    /**
     * @return list<array{label:string,value:string,hint:string}>
     */
    public function boundaryCards(): array
    {
        return [
            [
                'label' => __('ops.custom_pages.seo_intelligence.boundary.metabase_exposure'),
                'value' => __('ops.custom_pages.seo_intelligence.boundary.private_only'),
                'hint' => __('ops.custom_pages.seo_intelligence.boundary.metabase_exposure_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.boundary.datasource'),
                'value' => 'seo_intel',
                'hint' => __('ops.custom_pages.seo_intelligence.boundary.datasource_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.boundary.sharing'),
                'value' => __('ops.custom_pages.seo_intelligence.boundary.disabled'),
                'hint' => __('ops.custom_pages.seo_intelligence.boundary.sharing_hint'),
            ],
            [
                'label' => __('ops.custom_pages.seo_intelligence.boundary.operator_sql'),
                'value' => __('ops.custom_pages.seo_intelligence.boundary.blocked'),
                'hint' => __('ops.custom_pages.seo_intelligence.boundary.operator_sql_hint'),
            ],
        ];
    }

    /**
     * @return list<array{title:string,body:string}>
     */
    public function accessSteps(): array
    {
        return [
            [
                'title' => __('ops.custom_pages.seo_intelligence.access_steps.confirm_owner.title'),
                'body' => __('ops.custom_pages.seo_intelligence.access_steps.confirm_owner.body'),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.access_steps.private_channel.title'),
                'body' => __('ops.custom_pages.seo_intelligence.access_steps.private_channel.body'),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.access_steps.keep_private.title'),
                'body' => __('ops.custom_pages.seo_intelligence.access_steps.keep_private.body'),
            ],
            [
                'title' => __('ops.custom_pages.seo_intelligence.access_steps.datasource_boundary.title'),
                'body' => __('ops.custom_pages.seo_intelligence.access_steps.datasource_boundary.body'),
            ],
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.seo_intelligence');
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.seo_intelligence.title');
    }

    public static function canAccess(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function hasAnyPermission(array $permissions): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardSnapshot(): array
    {
        if ($this->dashboardSnapshot !== null) {
            return $this->dashboardSnapshot;
        }

        try {
            $this->dashboardSnapshot = [
                'available' => true,
                'overview' => app(SeoDashboardOverviewReadService::class)->read(),
                'url_truth' => app(SeoUrlTruthReadService::class)->read(),
                'issues' => app(SeoIssueQueueReadService::class)->read(5),
                'queue' => app(SeoSearchChannelQueueReadService::class)->read(5),
                'crawler' => app(SeoCrawlerLogObservationReadService::class)->read(5),
            ];
        } catch (Throwable) {
            $this->dashboardSnapshot = $this->emptyDashboardSnapshot();
        }

        return $this->dashboardSnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboardSnapshot(): array
    {
        return [
            'available' => false,
            'overview' => [
                'heartbeat' => [
                    ['key' => 'url_truth_total', 'label' => 'URL Truth URLs', 'value' => 0],
                    ['key' => 'url_entity_mapping_total', 'label' => 'URL Entities', 'value' => 0],
                    ['key' => 'issue_queue_total', 'label' => 'Issue Queue', 'value' => 0],
                    ['key' => 'search_channel_queue_item_total', 'label' => 'Search Channel Queue Items', 'value' => 0],
                    ['key' => 'search_channel_queue_batch_total', 'label' => 'Search Channel Queue Batches', 'value' => 0],
                    ['key' => 'search_channel_queue_event_total', 'label' => 'Search Channel Events', 'value' => 0],
                ],
                'safety' => [
                    ['key' => 'private_flow_count', 'label' => 'Private-flow leaks', 'value' => 0, 'alert' => false],
                    ['key' => 'forbidden_authority_count', 'label' => 'Forbidden authority', 'value' => 0, 'alert' => false],
                    ['key' => 'claim_unsafe_count', 'label' => 'Claim unsafe', 'value' => 0, 'alert' => false],
                ],
            ],
            'url_truth' => ['distributions' => []],
            'issues' => ['aggregates' => [], 'recent_rows' => []],
            'queue' => ['aggregates' => ['event_type' => []], 'recent_rows' => []],
            'crawler' => ['aggregates' => [], 'safety_counts' => [], 'recent_rows' => []],
        ];
    }

    /**
     * @return list<array{label:string,count:int}>
     */
    private function distributionRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn (mixed $row): array => [
                'label' => (string) data_get($row, 'label', '-'),
                'count' => (int) data_get($row, 'count', 0),
            ])
            ->values()
            ->all();
    }
}
