<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\SeoIntel\OpsDashboard\SeoCrawlerLogObservationReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoSearchChannelQueueReadService;
use Throwable;

/**
 * Read-only composition boundary for the canonical SEO Operations surface.
 *
 * This service owns no authority and performs no SQL. Every payload is delegated
 * to an existing CMS or seo_intel read model and receives a common availability
 * contract so the UI never turns missing data into a synthetic zero.
 */
final class SeoOperationsReadService
{
    public function __construct(private readonly ?string $connectionName = null) {}

    /**
     * @param  array{days?:int,device?:string,country?:string,locale?:string,search_type?:string}  $filters
     * @return array<string,array<string,mixed>>
     */
    public function read(array $filters = []): array
    {
        $reader = new SeoDashboardApiReadService($this->connectionName);
        $overview = $this->available('seo_intel.dashboard_overview', fn (): array => [
            'authority' => $reader->overview(),
            'url_truth' => $reader->urlTruth(),
            'issues' => $reader->issues(10),
            'search_channel' => (new SeoSearchChannelQueueReadService($this->connectionName))->read(10),
            'crawler' => (new SeoCrawlerLogObservationReadService($this->connectionName))->read(10),
            'scheduler' => [
                'state' => 'not_implemented',
                'source' => 'seo_intel.scheduler',
                'observed_at' => now()->toAtomString(),
                'unavailable_reason' => 'seo_intel_scheduler_receipt_not_implemented',
            ],
            'search_submission' => [
                'state' => 'measurement_hold',
                'enabled' => false,
                'source' => 'search_channel_queue_policy',
                'observed_at' => now()->toAtomString(),
                'unavailable_reason' => 'global_search_submission_disabled',
            ],
        ]);

        return [
            'overview' => $overview,
            'performance' => $this->available('seo_intel.seo_gsc_daily', fn (): array => [
                'gsc' => $reader->searchPerformance($filters),
                'public_funnel' => $reader->conversionFunnel(0, ['group_by' => 'url'], 25),
            ]),
            'technical' => $this->available('seo_intel.technical_read_models', fn (): array => [
                'audit' => $reader->technicalAudits(25),
                'url_truth' => $reader->urlTruth(),
                'crawler' => (new SeoCrawlerLogObservationReadService($this->connectionName))->read(10),
            ]),
            'opportunities' => $this->available('seo_intel.opportunity_queue', fn (): array => $this->opportunities($reader)),
            'ai' => [
                'state' => 'not_implemented',
                'source' => 'seo_agent_runtime',
                'observed_at' => now()->toAtomString(),
                'updated_at' => null,
                'unavailable_reason' => 'seo_agent_operations_workspace_not_implemented',
                'connected' => false,
                'recommendations' => [],
                'risk_caps' => (array) data_get($overview, 'url_truth.page_family_policy.agent_risk_caps', []),
                'blocking_reasons' => ['not_implemented'],
            ],
            'execution' => $this->available('seo_intel.issue_and_search_channel_queues', fn (): array => [
                'issues' => (new SeoIssueQueueReadService($this->connectionName))->read(25),
                'clusters' => $reader->issueClusters([], 1, 25),
                'search_channel' => (new SeoSearchChannelQueueReadService($this->connectionName))->read(25),
                'boundaries' => [
                    'cms_publish_allowed' => false,
                    'url_truth_write_allowed' => false,
                    'gsc_write_allowed' => false,
                    'search_submission_allowed' => false,
                    'career_canary_sequence' => ['1-3', '10', '50', 'cohort'],
                ],
            ]),
        ];
    }

    /** @param array<string,string> $filters @return array<string,mixed> */
    public function issueClusters(array $filters, int $page, int $perPage): array
    {
        return $this->sanitize((new SeoDashboardApiReadService($this->connectionName))->issueClusters($filters, $page, $perPage));
    }

    /** @param array<string,string> $filters @return array<string,mixed> */
    public function issueClusterUrls(string $clusterUid, array $filters, int $page, int $perPage): array
    {
        return $this->sanitize((new SeoDashboardApiReadService($this->connectionName))->issueClusterUrls($clusterUid, $filters, $page, $perPage));
    }

    /** @param array<string,string> $filters @return array<string,mixed> */
    public function issueClusterExport(array $filters): array
    {
        return $this->sanitize((new SeoDashboardApiReadService($this->connectionName))->issueClusterExport($filters));
    }

    /** @return array<string,mixed> */
    public function pageInspector(string $issueUid): array
    {
        return $this->sanitize((new SeoDashboardApiReadService($this->connectionName))->pageInspector($issueUid));
    }

    /** @param callable():array<string,mixed> $read @return array<string,mixed> */
    private function available(string $source, callable $read): array
    {
        $observedAt = now()->toAtomString();

        try {
            $payload = $this->sanitize($read());

            return [
                'state' => 'connected',
                'source' => $source,
                'observed_at' => $observedAt,
                'updated_at' => $observedAt,
                'unavailable_reason' => null,
                ...$payload,
            ];
        } catch (Throwable) {
            return [
                'state' => 'unavailable',
                'source' => $source,
                'observed_at' => $observedAt,
                'updated_at' => null,
                'unavailable_reason' => 'read_model_unavailable',
            ];
        }
    }

    /** @return array<string,mixed> */
    private function opportunities(SeoDashboardApiReadService $reader): array
    {
        $payload = $reader->opportunityQueue(25);
        $observedAt = now()->toAtomString();
        $payload['recent_rows'] = array_map(static function (array $row) use ($observedAt): array {
            return [
                'state' => 'measurement_hold',
                'source' => 'seo_intel.opportunity_queue',
                'observed_at' => $observedAt,
                'updated_at' => $row['updated_at'] ?? null,
                'unavailable_reason' => 'human_review_required_before_cms_or_search_action',
                'query_owner' => $row['query_owner'] ?? 'measurement_hold',
                'page_family' => $row['page_family'] ?? 'measurement_hold',
                ...$row,
            ];
        }, (array) ($payload['recent_rows'] ?? []));

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitize(array $payload): array
    {
        foreach (['canonical_url_hash', 'query_hash', 'evidence_hash', 'evidence_fingerprint', 'session_id_hash'] as $forbiddenKey) {
            unset($payload[$forbiddenKey]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = array_is_list($value)
                    ? array_map(fn (mixed $row): mixed => is_array($row) ? $this->sanitize($row) : $row, $value)
                    : $this->sanitize($value);
            }
        }

        return $payload;
    }
}
