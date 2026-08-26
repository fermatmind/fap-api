<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CrawlerLogScheduledAggregateCollector
{
    public function __construct(
        private readonly CrawlerLogSingleSourceReader $sourceReader,
        private readonly CrawlerLogFixtureParser $parser,
        private readonly CrawlerLogAggregateDryRun $aggregator,
        private readonly CrawlerLogAggregateStorageWriter $writer,
    ) {}

    /** @return array<string,mixed> */
    public function collect(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $issues = $this->gateIssues();
        $sourcePath = trim((string) config('seo_intel.crawler_log_source', ''));

        if ($sourcePath === '' || ! str_starts_with($sourcePath, '/')) {
            $issues[] = 'approved_source_missing';
        }

        if ($issues !== []) {
            return $this->blocked(array_values(array_unique($issues)));
        }

        $limit = min(
            CrawlerLogAggregateDryRun::MAX_LIMIT,
            max(1, (int) config('seo_intel.crawler_log_aggregate_storage.max_lines', 1000)),
        );

        try {
            $lines = $this->sourceReader->readTail($sourcePath, $limit);
        } catch (RuntimeException $exception) {
            return $this->blocked([$exception->getMessage()]);
        }

        $parsed = $this->parser->parseLines($lines, 'nginx_access_log', true);
        $eligibleRows = array_values(array_filter(
            (array) ($parsed['sanitized_rows'] ?? []),
            fn (mixed $row): bool => is_array($row) && $this->eligible($row, $now),
        ));
        $parsed['sanitized_rows'] = $eligibleRows;
        $aggregated = $this->aggregator->fromParsedReport($parsed, []);
        $aggregateRows = array_values(array_filter(
            (array) ($aggregated['aggregate_rows'] ?? []),
            'is_array',
        ));

        if ($aggregateRows === []) {
            return $this->blocked(
                ['no_recent_crawler_observation'],
                sourceRead: true,
                sourceLineCount: count($lines),
            );
        }

        try {
            $write = DB::connection((string) config('seo_intel.connection', 'seo_intel'))->transaction(
                fn (): array => $this->writer->write($aggregateRows, dryRun: false, noWrite: false),
            );
        } catch (Throwable) {
            return $this->blocked(
                ['aggregate_write_failed'],
                sourceRead: true,
                sourceLineCount: count($lines),
            );
        }

        if (($write['status'] ?? null) !== 'success'
            || ($write['writes_committed'] ?? null) !== true
            || (int) ($write['written_rows'] ?? 0) !== count($aggregateRows)) {
            return $this->blocked(
                ['aggregate_write_incomplete'],
                sourceRead: true,
                sourceLineCount: count($lines),
            );
        }

        return [
            'schema_version' => 'seo-platform-07-crawler-aggregate-runtime.v1',
            'status' => 'success',
            'trigger_mode' => 'scheduled',
            'scheduler_enabled' => true,
            'production_log_read_attempted' => true,
            'writes_attempted' => true,
            'writes_committed' => true,
            'source_line_count' => count($lines),
            'eligible_crawler_observation_count' => count($eligibleRows),
            'aggregate_row_count' => count($aggregateRows),
            'written_row_count' => (int) $write['written_rows'],
            'source_identity_hash' => hash('sha256', $sourcePath),
            'raw_persistence' => false,
            'raw_url_emitted' => false,
            'query_emitted' => false,
            'user_agent_emitted' => false,
            'search_submission_attempted' => false,
            'issues' => [],
        ];
    }

    /** @return list<string> */
    private function gateIssues(): array
    {
        $gates = [
            'scheduler_gate_disabled' => (bool) config('seo_intel.crawler_log_aggregate_storage.scheduler_enabled', false),
            'production_log_read_gate_disabled' => (bool) config('seo_intel.crawler_log_aggregate_storage.production_log_read_allowed', false),
            'aggregate_write_gate_disabled' => (bool) config('seo_intel.crawler_log_aggregate_storage.write_enabled', false),
        ];

        return array_keys(array_filter($gates, static fn (bool $enabled): bool => ! $enabled));
    }

    /** @param array<string,mixed> $row */
    private function eligible(array $row, CarbonImmutable $now): bool
    {
        if (in_array($row['bot_family'] ?? null, [null, 'non_bot', 'unknown_user_agent'], true)
            || ! in_array($row['method_bucket'] ?? null, ['GET', 'HEAD'], true)) {
            return false;
        }

        $status = $row['http_status'] ?? null;
        if (! is_int($status) || $status < 200 || $status >= 400) {
            return false;
        }

        try {
            $observedAt = CarbonImmutable::parse((string) ($row['last_seen_at'] ?? ''))->utc();
        } catch (Throwable) {
            return false;
        }

        $ageMinutes = $observedAt->diffInMinutes($now, false);
        $maximumAge = max(1, (int) config('seo_intel.crawler_log_aggregate_storage.maximum_source_age_minutes', 2880));

        return $ageMinutes >= 0 && $ageMinutes <= $maximumAge;
    }

    /** @param list<string> $issues @return array<string,mixed> */
    private function blocked(array $issues, bool $sourceRead = false, int $sourceLineCount = 0): array
    {
        return [
            'schema_version' => 'seo-platform-07-crawler-aggregate-runtime.v1',
            'status' => 'MEASUREMENT_HOLD',
            'trigger_mode' => 'scheduled',
            'scheduler_enabled' => (bool) config('seo_intel.crawler_log_aggregate_storage.scheduler_enabled', false),
            'production_log_read_attempted' => $sourceRead,
            'writes_attempted' => false,
            'writes_committed' => false,
            'source_line_count' => $sourceLineCount,
            'eligible_crawler_observation_count' => 0,
            'aggregate_row_count' => 0,
            'written_row_count' => 0,
            'source_identity_hash' => null,
            'raw_persistence' => false,
            'raw_url_emitted' => false,
            'query_emitted' => false,
            'user_agent_emitted' => false,
            'search_submission_attempted' => false,
            'issues' => $issues,
        ];
    }
}
