<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

use RuntimeException;

final class CrawlerLogProductionCanaryDryRun
{
    private const SOURCE_LOG_FAMILY = 'nginx_access_log';

    public function __construct(
        private readonly CrawlerLogSingleSourceReader $sourceReader,
        private readonly CrawlerLogFixtureParser $parser,
        private readonly CrawlerLogAggregateDryRun $aggregateDryRun,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(string $sourcePath, int $limit, ?string $approvalPhrase, bool $dryRun, bool $noWrite): array
    {
        $boundedLimit = $this->boundedLimit($limit);
        $issues = [];

        if (! $dryRun) {
            $issues[] = 'dry_run_required';
        }

        if (! $noWrite) {
            $issues[] = 'no_write_required';
        }

        if ($approvalPhrase !== $this->expectedApprovalPhrase($sourcePath)) {
            $issues[] = 'exact_approval_phrase_required';
        }

        if ($issues !== []) {
            return $this->blocked($sourcePath, $limit, $boundedLimit, $issues);
        }

        try {
            $lines = $this->sourceReader->read($sourcePath, $boundedLimit);
        } catch (RuntimeException $exception) {
            return $this->blocked(
                $sourcePath,
                $limit,
                $boundedLimit,
                [$exception->getMessage()],
            );
        }

        $parseReport = $this->parser->parseLines(
            $lines,
            self::SOURCE_LOG_FAMILY,
            true,
        );

        return $this->aggregateDryRun->fromParsedReport(
            $parseReport,
            [
                'runtime' => 'crawler_log_observe',
                'status' => 'success',
                'mode' => 'single_source_production_canary_dry_run',
                'fixture_only' => false,
                'source_canary' => true,
                'dry_run' => true,
                'no_write' => true,
                'writes_attempted' => false,
                'writes_committed' => false,
                'production_log_read_attempted' => true,
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
                'scheduler_enabled' => false,
                'collector_write_attempted' => false,
                'raw_persistence' => false,
                'source_log_family' => self::SOURCE_LOG_FAMILY,
                'source_descriptor' => $this->sourceReader->descriptor($sourcePath),
                'approval_phrase_verified' => true,
                'requested_limit' => $limit,
                'effective_limit' => $boundedLimit,
                'source_line_count_read' => count($lines),
                'target_table' => CrawlerLogAggregateDryRun::TARGET_TABLE,
                'target_table_write_attempted' => false,
                'target_table_write_committed' => false,
                'safety_flags' => [
                    'single_source_only' => true,
                    'no_raw_persistence' => true,
                    'no_database_writes' => true,
                    'no_issue_queue_write' => true,
                    'no_url_truth_write' => true,
                    'no_search_submission' => true,
                    'no_external_calls' => true,
                    'no_scheduler' => true,
                ],
            ],
        );
    }

    public function expectedApprovalPhrase(string $sourcePath): string
    {
        return sprintf(
            'I explicitly approve CRAWLER-LOG-04 production canary for source %s with max_lines=1000 and no raw persistence.',
            $sourcePath,
        );
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blocked(string $sourcePath, int $requestedLimit, int $effectiveLimit, array $issues): array
    {
        return [
            'runtime' => 'crawler_log_observe',
            'status' => 'blocked',
            'mode' => 'single_source_production_canary_dry_run',
            'fixture_only' => false,
            'source_canary' => true,
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
            'parsed_line_count' => 0,
            'sanitized_row_count' => 0,
            'aggregate_row_count' => 0,
            'blocked_private_path_count' => 0,
            'unknown_bot_count' => 0,
            'bot_family_breakdown' => [],
            'status_code_breakdown' => [],
            'route_family_breakdown' => [],
            'surface_family_breakdown' => [],
            'query_risk_state_breakdown' => [],
            'method_bucket_breakdown' => [],
            'safe_public_canonical_path_count' => 0,
            'aggregate_rows' => [],
            'target_table' => CrawlerLogAggregateDryRun::TARGET_TABLE,
            'target_table_write_attempted' => false,
            'target_table_write_committed' => false,
            'privacy_transform_version' => CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION,
            'source_descriptor' => $this->sourceReader->descriptor($sourcePath),
            'approval_phrase_verified' => false,
            'requested_limit' => $requestedLimit,
            'effective_limit' => $effectiveLimit,
            'source_line_count_read' => 0,
            'issues' => $issues,
            'safety_flags' => [
                'single_source_only' => true,
                'no_raw_persistence' => true,
                'no_database_writes' => true,
                'no_issue_queue_write' => true,
                'no_url_truth_write' => true,
                'no_search_submission' => true,
                'no_external_calls' => true,
                'no_scheduler' => true,
            ],
        ];
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, CrawlerLogAggregateDryRun::MAX_LIMIT));
    }
}
