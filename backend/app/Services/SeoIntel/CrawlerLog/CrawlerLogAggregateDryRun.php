<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

final class CrawlerLogAggregateDryRun
{
    public const TARGET_TABLE = 'seo_crawler_logs_daily';

    public const MAX_LIMIT = 1000;

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    public function report(array $lines, int $limit = 100): array
    {
        $limit = $this->boundedLimit($limit);
        $limitedLines = array_slice($lines, 0, $limit);
        $parseReport = (new CrawlerLogFixtureParser)->parseLines($limitedLines);

        return $this->fromParsedReport(
            $parseReport,
            [
                'runtime' => 'crawler_log_observe',
                'status' => 'success',
                'mode' => 'synthetic_fixture_aggregate_dry_run',
                'fixture_only' => true,
                'dry_run' => true,
                'no_write' => true,
                'writes_attempted' => false,
                'writes_committed' => false,
                'production_log_read_attempted' => false,
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
                'scheduler_enabled' => false,
                'collector_write_attempted' => false,
                'raw_persistence' => false,
                'target_table' => self::TARGET_TABLE,
                'target_table_write_attempted' => false,
                'target_table_write_committed' => false,
                'safety_flags' => [
                    'no_production_log_read' => true,
                    'no_database_writes' => true,
                    'no_collector_write' => true,
                    'no_scheduler' => true,
                    'no_external_search_api_call' => true,
                    'no_search_submission' => true,
                    'no_url_truth_creation' => true,
                    'no_issue_queue_auto_write' => true,
                    'no_search_channel_queue_creation' => true,
                    'no_metabase_exposure' => true,
                    'no_business_db_access' => true,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $parseReport
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function fromParsedReport(array $parseReport, array $overrides): array
    {
        $sanitizedRows = $parseReport['sanitized_rows'];
        $aggregateRows = $this->aggregateRows($sanitizedRows);

        return array_replace([
            'runtime' => 'crawler_log_observe',
            'status' => 'success',
            'mode' => 'aggregate_dry_run',
            'fixture_only' => false,
            'dry_run' => true,
            'no_write' => true,
            'writes_attempted' => false,
            'writes_committed' => false,
            'production_log_read_attempted' => false,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'scheduler_enabled' => false,
            'collector_write_attempted' => false,
            'raw_persistence' => false,
            'parsed_line_count' => $parseReport['parsed_line_count'],
            'sanitized_row_count' => $parseReport['sanitized_row_count'],
            'aggregate_row_count' => count($aggregateRows),
            'blocked_private_path_count' => $parseReport['blocked_private_path_count'],
            'unknown_bot_count' => $parseReport['unknown_bot_count'],
            'bot_family_breakdown' => $parseReport['bot_family_breakdown'],
            'status_code_breakdown' => $parseReport['status_code_breakdown'],
            'route_family_breakdown' => $parseReport['route_family_breakdown'],
            'surface_family_breakdown' => $this->countBy($sanitizedRows, 'surface_family'),
            'query_risk_state_breakdown' => $this->countBy($sanitizedRows, 'query_risk_state'),
            'method_bucket_breakdown' => $this->countBy($sanitizedRows, 'method_bucket'),
            'safe_public_canonical_path_count' => count(array_filter(
                $sanitizedRows,
                static fn (array $row): bool => ($row['canonical_path'] ?? null) !== null,
            )),
            'target_table' => self::TARGET_TABLE,
            'target_table_write_attempted' => false,
            'target_table_write_committed' => false,
            'privacy_transform_version' => CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION,
            'aggregate_rows' => $aggregateRows,
            'safety_flags' => [],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $aggregate = $this->aggregateDimensions($row);
            $key = json_encode($aggregate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (! isset($groups[$key])) {
                $groups[$key] = $aggregate + [
                    'hit_count' => 0,
                    'first_seen_at' => $row['first_seen_at'] ?? null,
                    'last_seen_at' => $row['last_seen_at'] ?? null,
                ];
            }

            $groups[$key]['hit_count'] = ((int) $groups[$key]['hit_count']) + max(1, (int) ($row['hit_count'] ?? 1));
            $groups[$key]['first_seen_at'] = $this->earlierTimestamp(
                $groups[$key]['first_seen_at'] ?? null,
                $row['first_seen_at'] ?? null,
            );
            $groups[$key]['last_seen_at'] = $this->laterTimestamp(
                $groups[$key]['last_seen_at'] ?? null,
                $row['last_seen_at'] ?? null,
            );
        }

        $result = array_values($groups);
        usort($result, static function (array $left, array $right): int {
            return [
                $left['log_date'] ?? '',
                $left['host'] ?? '',
                $left['surface_family'] ?? '',
                $left['route_family'] ?? '',
                $left['bot_family'] ?? '',
                $left['canonical_path'] ?? '',
                $left['path_hash'] ?? '',
            ] <=> [
                $right['log_date'] ?? '',
                $right['host'] ?? '',
                $right['surface_family'] ?? '',
                $right['route_family'] ?? '',
                $right['bot_family'] ?? '',
                $right['canonical_path'] ?? '',
                $right['path_hash'] ?? '',
            ];
        });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateDimensions(array $row): array
    {
        $fields = [
            'log_date',
            'host',
            'surface_family',
            'bot_family',
            'bot_variant',
            'bot_verification_state',
            'route_family',
            'page_entity_type',
            'canonical_path',
            'path_hash',
            'http_status',
            'method_bucket',
            'query_present',
            'query_risk_state',
            'private_path_blocked',
            'source_log_family',
            'privacy_transform_version',
        ];

        $aggregate = [];

        foreach ($fields as $field) {
            $aggregate[$field] = $row[$field] ?? null;
        }

        return $aggregate;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    private function earlierTimestamp(mixed $left, mixed $right): ?string
    {
        if (! is_string($left) || $left === '') {
            return is_string($right) && $right !== '' ? $right : null;
        }

        if (! is_string($right) || $right === '') {
            return $left;
        }

        return $right < $left ? $right : $left;
    }

    private function laterTimestamp(mixed $left, mixed $right): ?string
    {
        if (! is_string($left) || $left === '') {
            return is_string($right) && $right !== '' ? $right : null;
        }

        if (! is_string($right) || $right === '') {
            return $left;
        }

        return $right > $left ? $right : $left;
    }
}
