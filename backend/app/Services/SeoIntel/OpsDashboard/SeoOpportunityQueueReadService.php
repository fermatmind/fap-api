<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\GscDataQualityGate;

final class SeoOpportunityQueueReadService extends AbstractSeoDashboardReadService
{
    public function __construct(
        ?string $connectionName = null,
        private readonly GscDataQualityGate $dataQualityGate = new GscDataQualityGate,
    ) {
        parent::__construct($connectionName);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->gscRows();
        $qualityGate = $this->dataQualityGate->evaluate($rows);

        return [
            'schema_version' => 'seo-opportunity-queue-readonly.v1',
            'mode' => 'read_only',
            'source_gate' => $qualityGate,
            'total_count' => $qualityGate['opportunity_queue_eligible'] ? count($this->candidateRows($rows, $limit)) : 0,
            'recent_rows' => $qualityGate['opportunity_queue_eligible'] ? $this->candidateRows($rows, $limit) : [],
            'scoring_contract' => [
                'inputs' => ['seo_gsc_daily', 'seo_urls', 'gsc_data_quality_gate'],
                'min_impressions' => 50,
                'max_ctr_ppm' => 10000,
                'position_milli_window' => [8000, 20000],
                'brand_query_allowed' => false,
            ],
            'boundaries' => [
                'cms_draft_allowed' => false,
                'cms_write_allowed' => false,
                'search_channel_enqueue_allowed' => false,
                'search_provider_submission_allowed' => false,
                'execution_allowed' => false,
                'external_calls_attempted' => false,
                'writes_attempted' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gscRows(): array
    {
        return $this->table('seo_gsc_daily')
            ->select([
                'report_date',
                'canonical_url_hash',
                'canonical_url',
                'query_hash',
                'query_display_masked',
                'locale',
                'source_engine',
                'clicks',
                'impressions',
                'ctr_ppm',
                'average_position_milli',
                'is_brand_query',
                'query_type',
                'metadata_json',
            ])
            ->where('source_engine', 'google')
            ->orderByDesc('report_date')
            ->limit(500)
            ->get()
            ->map(fn (object $row): array => [
                'report_date' => (string) $row->report_date,
                'canonical_url_hash' => (string) $row->canonical_url_hash,
                'canonical_url' => is_string($row->canonical_url ?? null) ? $row->canonical_url : null,
                'query_hash' => (string) $row->query_hash,
                'query_display_masked' => is_string($row->query_display_masked ?? null) ? $row->query_display_masked : null,
                'locale' => is_string($row->locale ?? null) ? $row->locale : null,
                'source_engine' => (string) $row->source_engine,
                'clicks' => (int) ($row->clicks ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'ctr_ppm' => $row->ctr_ppm === null ? null : (int) $row->ctr_ppm,
                'average_position_milli' => $row->average_position_milli === null ? null : (int) $row->average_position_milli,
                'is_brand_query' => (bool) ($row->is_brand_query ?? false),
                'query_type' => is_string($row->query_type ?? null) ? $row->query_type : 'unknown',
                'metadata_json' => $this->decodeJson($row->metadata_json ?? null),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function candidateRows(array $rows, int $limit): array
    {
        $candidates = array_values(array_filter($rows, static function (array $row): bool {
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $ctrPpm = $row['ctr_ppm'] === null ? ($impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : null) : (int) $row['ctr_ppm'];
            $positionMilli = $row['average_position_milli'] === null ? null : (int) $row['average_position_milli'];

            return $impressions >= 50
                && $ctrPpm !== null
                && $ctrPpm <= 10000
                && $positionMilli !== null
                && $positionMilli >= 8000
                && $positionMilli <= 20000
                && ! (bool) ($row['is_brand_query'] ?? false)
                && ($row['query_type'] ?? 'unknown') === 'non_brand';
        }));

        usort($candidates, static function (array $left, array $right): int {
            $leftScore = ((int) $left['impressions']) - ((int) ($left['ctr_ppm'] ?? 0) / 1000);
            $rightScore = ((int) $right['impressions']) - ((int) ($right['ctr_ppm'] ?? 0) / 1000);

            return $rightScore <=> $leftScore;
        });

        return array_slice(array_map(fn (array $row): array => [
            'opportunity_id' => hash('sha256', implode('|', [
                (string) $row['report_date'],
                (string) $row['canonical_url_hash'],
                (string) $row['query_hash'],
            ])),
            'canonical_path' => $this->safePath(is_string($row['canonical_url'] ?? null) ? $row['canonical_url'] : null),
            'canonical_url_hash' => (string) $row['canonical_url_hash'],
            'query_hash' => (string) $row['query_hash'],
            'query_display_masked' => $row['query_display_masked'],
            'locale' => $row['locale'],
            'source_signal' => 'gsc:google',
            'report_date' => (string) $row['report_date'],
            'metrics' => [
                'clicks' => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr_ppm' => $row['ctr_ppm'],
                'average_position_milli' => $row['average_position_milli'],
            ],
            'recommended_next_step' => 'human_review_required_before_cms_or_search_action',
            'allowed_action' => 'read_only_review',
        ], $candidates), 0, $limit);
    }
}
