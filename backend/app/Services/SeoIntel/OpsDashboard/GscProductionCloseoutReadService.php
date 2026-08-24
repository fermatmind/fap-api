<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\GscRunCloseoutSummarizer;
use Illuminate\Support\Facades\Schema;

/** Aggregate-only production evidence over persisted SEO read models. */
final class GscProductionCloseoutReadService extends AbstractSeoDashboardReadService
{
    private const REQUIRED_TABLES = [
        'seo_urls',
        'seo_gsc_daily',
        'seo_gsc_data_quality_queue',
        'seo_issue_queue',
    ];

    private const REQUIRED_GSC_COLUMNS = [
        'report_date',
        'canonical_url_hash',
        'canonical_url',
        'query_hash',
        'source_engine',
        'device',
        'country',
        'search_type',
        'clicks',
        'impressions',
        'average_position_milli',
    ];

    public function __construct(
        ?string $connectionName = null,
        private readonly ?GscRunCloseoutSummarizer $summarizer = null,
    ) {
        parent::__construct($connectionName);
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $connectionName = $this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel');
        $schema = Schema::connection($connectionName);
        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => ! $schema->hasTable($table),
        ));
        $missingColumns = $schema->hasTable('seo_gsc_daily')
            ? array_values(array_filter(
                self::REQUIRED_GSC_COLUMNS,
                static fn (string $column): bool => ! $schema->hasColumn('seo_gsc_daily', $column),
            ))
            : self::REQUIRED_GSC_COLUMNS;

        if ($missingTables !== [] || $missingColumns !== []) {
            return [
                'schema_version' => 'seo-gsc-production-closeout-readonly.v1',
                'state' => 'unavailable',
                'missing_tables' => $missingTables,
                'missing_gsc_columns' => $missingColumns,
                'boundaries' => $this->boundaries(),
            ];
        }

        $summary = ($this->summarizer ?? app(GscRunCloseoutSummarizer::class))
            ->summarizeCurrentReadModel($this->connection(), 90, ['web']);
        $quality = $this->qualityQueueSummary();
        $issues = $this->issueQueueSummary();

        return [
            'schema_version' => 'seo-gsc-production-closeout-readonly.v1',
            'state' => ($summary['state'] ?? 'unavailable') === 'verified' ? 'verified' : 'unavailable',
            ...$summary,
            'queue_reconciliation' => [
                'gsc_data_quality_queue' => $quality,
                'seo_issue_queue' => $issues,
                'relationship' => [
                    'shared_unique_url_hash_count' => $this->sharedUrlHashCount(),
                    'direct_foreign_key_or_row_equivalence' => false,
                    'quality_queue_unit' => 'report_date_plus_canonical_url_hash_plus_issue_code',
                    'issue_queue_unit' => 'independent_operational_issue',
                    'quality_items_are_issue_clusters' => false,
                ],
            ],
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string, mixed> */
    private function qualityQueueSummary(): array
    {
        $status = $this->groupedCounts('seo_gsc_data_quality_queue', 'status');
        $open = collect($status)->firstWhere('label', 'open')['count'] ?? 0;
        $total = $this->table('seo_gsc_data_quality_queue')->count();

        return [
            'total_count' => $total,
            'open_count' => (int) $open,
            'processed_count' => max(0, $total - (int) $open),
            'status_distribution' => $status,
            'unique_url_hash_count' => $this->table('seo_gsc_data_quality_queue')
                ->whereNotNull('canonical_url_hash')
                ->distinct()
                ->count('canonical_url_hash'),
            'distinct_root_cause_count' => $this->table('seo_gsc_data_quality_queue')
                ->distinct()
                ->count('issue_code'),
            'root_cause_distribution' => $this->groupedCounts('seo_gsc_data_quality_queue', 'issue_code'),
            'historical_snapshot_semantics' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function issueQueueSummary(): array
    {
        $rows = $this->table('seo_issue_queue')
            ->get(['canonical_url_hash', 'issue_type', 'status', 'lifecycle_state', 'metadata_json']);
        $rootCauses = [];
        $open = 0;

        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            $lifecycle = strtolower(trim((string) ($row->lifecycle_state ?? '')));
            if (! in_array($status, ['resolved', 'verified', 'closed', 'ignored'], true)
                && ! in_array($lifecycle, ['resolved', 'closed', 'ignored'], true)) {
                $open++;
            }
            $metadata = $this->decodeJson($row->metadata_json ?? null);
            $rootCause = trim((string) ($metadata['root_cause'] ?? $row->issue_type ?? 'unknown')) ?: 'unknown';
            $rootCauses[$rootCause] = ($rootCauses[$rootCause] ?? 0) + 1;
        }
        ksort($rootCauses);
        $total = $rows->count();

        return [
            'total_count' => $total,
            'open_count' => $open,
            'processed_count' => max(0, $total - $open),
            'status_distribution' => $this->groupedCounts('seo_issue_queue', 'status'),
            'unique_url_hash_count' => $rows->pluck('canonical_url_hash')->filter()->unique()->count(),
            'distinct_root_cause_count' => count($rootCauses),
            'root_cause_distribution' => array_map(
                static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
                array_keys($rootCauses),
                array_values($rootCauses),
            ),
            'active_cluster_summary' => (new SeoIssueClusterReadService($this->connectionName))->closeoutSummary(),
        ];
    }

    private function sharedUrlHashCount(): int
    {
        $quality = $this->table('seo_gsc_data_quality_queue')
            ->whereNotNull('canonical_url_hash')
            ->distinct()
            ->pluck('canonical_url_hash');
        $issues = $this->table('seo_issue_queue')
            ->whereNotNull('canonical_url_hash')
            ->distinct()
            ->pluck('canonical_url_hash');

        return $quality->intersect($issues)->unique()->count();
    }

    /** @return array<string, bool> */
    private function boundaries(): array
    {
        return [
            'read_only' => true,
            'aggregate_only' => true,
            'raw_query_emitted' => false,
            'raw_url_emitted' => false,
            'url_hash_emitted' => false,
            'external_calls_attempted' => false,
            'writes_attempted' => false,
            'url_truth_write_allowed' => false,
            'issue_mutation_allowed' => false,
            'cms_publish_allowed' => false,
            'search_submission_allowed' => false,
        ];
    }
}
