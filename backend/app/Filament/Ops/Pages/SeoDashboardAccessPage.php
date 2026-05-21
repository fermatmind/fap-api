<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

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

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'SEO Intelligence';

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
                'label' => 'URL Truth rows',
                'value' => '7',
                'hint' => 'Verified `seo_urls` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Entity mappings',
                'value' => '7',
                'hint' => 'Verified `seo_url_entities` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Issue queue rows',
                'value' => '5',
                'hint' => 'Verified `seo_issue_queue` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Verified cards',
                'value' => '10',
                'hint' => 'Metabase dashboard card count verified before this route shell PR.',
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
                    'url_truth_total' => 'URL Truth rows',
                    'url_entity_mapping_total' => 'Entity mappings',
                    'issue_queue_total' => 'Issue queue rows',
                    default => (string) ($card['label'] ?? 'Metric'),
                };

                return [
                    'label' => $label,
                    'value' => (string) ((int) ($card['value'] ?? 0)),
                    'hint' => match ($key) {
                        'url_truth_total' => 'Live count from seo_urls on the seo_intel connection.',
                        'url_entity_mapping_total' => 'Live count from seo_url_entities on the seo_intel connection.',
                        'issue_queue_total' => 'Live count from seo_issue_queue on the seo_intel connection.',
                        'search_channel_queue_item_total' => 'Live count from seo_search_channel_queue_items.',
                        'search_channel_queue_batch_total' => 'Live count from seo_search_channel_queue_batches.',
                        'search_channel_queue_event_total' => 'Live count from seo_search_channel_queue_events.',
                        default => 'Live read-only seo_intel metric.',
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
            ->map(fn (array $card): array => [
                'label' => (string) ($card['label'] ?? 'Safety counter'),
                'value' => (string) ((int) ($card['value'] ?? 0)),
                'hint' => ((bool) ($card['alert'] ?? false))
                    ? 'Non-zero safety counter. Treat as an Ops warning before search distribution.'
                    : 'Expected steady state is zero.',
                'kind' => 'pill',
                'state' => ((bool) ($card['alert'] ?? false)) ? 'danger' : 'success',
            ])
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
                'title' => 'page_entity_type',
                'rows' => $this->distributionRows($distributions['page_entity_type'] ?? []),
            ],
            [
                'title' => 'locale',
                'rows' => $this->distributionRows($distributions['locale'] ?? []),
            ],
            [
                'title' => 'source_authority',
                'rows' => $this->distributionRows($distributions['source_authority'] ?? []),
            ],
            [
                'title' => 'indexability_state',
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
                'title' => 'issue_type',
                'rows' => $this->distributionRows($aggregates['issue_type'] ?? []),
            ],
            [
                'title' => 'severity',
                'rows' => $this->distributionRows($aggregates['severity'] ?? []),
            ],
            [
                'title' => 'status',
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
                'title' => 'channel',
                'rows' => $this->distributionRows($aggregates['channel'] ?? []),
            ],
            [
                'title' => 'approval_state',
                'rows' => $this->distributionRows($aggregates['approval_state'] ?? []),
            ],
            [
                'title' => 'execution_state',
                'rows' => $this->distributionRows($aggregates['execution_state'] ?? []),
            ],
        ];
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
                'label' => 'Metabase exposure',
                'value' => 'Private only',
                'hint' => 'Metabase remains localhost-bound on the approved private ECS host.',
            ],
            [
                'label' => 'Datasource',
                'value' => 'seo_intel',
                'hint' => 'The only approved Metabase datasource uses the readonly account.',
            ],
            [
                'label' => 'Sharing',
                'value' => 'Disabled',
                'hint' => 'Public sharing, anonymous links, and public embedding remain blocked.',
            ],
            [
                'label' => 'Operator SQL',
                'value' => 'Blocked',
                'hint' => 'Normal operators must not receive unrestricted native SQL access.',
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
                'title' => 'Confirm owner approval',
                'body' => 'Use this page as the Ops entry point and confirm the Metabase admin, dashboard, DB access, export policy, and emergency revoke owners before private access.',
            ],
            [
                'title' => 'Use a private channel',
                'body' => 'Access Metabase only through Workbench, bastion, VPN, or another approved owner-controlled private channel.',
            ],
            [
                'title' => 'Keep Metabase private',
                'body' => 'Do not iframe, reverse-proxy, publish, expose, or bind Metabase to a public interface from this page.',
            ],
            [
                'title' => 'Verify datasource boundary',
                'body' => 'The only approved datasource is `seo_intel` through `seo_intel_metabase_readonly`; business DB, Tencent RDS, Node2, and raw operational sources remain forbidden.',
            ],
        ];
    }

    public function getTitle(): string
    {
        return 'SEO Intelligence Access';
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
