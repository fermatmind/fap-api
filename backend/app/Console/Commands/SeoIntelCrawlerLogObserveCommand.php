<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogAggregateDryRun;
use App\Services\SeoIntel\CrawlerLog\CrawlerLogFixtureParser;
use App\Services\SeoIntel\CrawlerLog\CrawlerLogProductionCanaryDryRun;
use Illuminate\Console\Command;

final class SeoIntelCrawlerLogObserveCommand extends Command
{
    protected $signature = 'seo-intel:crawler-log-observe
        {--fixture : Use the bundled synthetic crawler-log fixture}
        {--source= : Single absolute crawler-log source path for a human-approved canary dry-run}
        {--approval-phrase= : Exact human approval phrase for single-source canary reads}
        {--dry-run : Keep crawler-log observation in dry-run mode}
        {--no-write : Prevent all persistence}
        {--json : Output safe machine-readable JSON}
        {--limit=100 : Maximum source lines to inspect (capped at 1000)}';

    protected $description = 'Aggregate sanitized crawler-log lines from a fixture or a single human-approved source without writes.';

    public function handle(
        CrawlerLogAggregateDryRun $aggregateDryRun,
        CrawlerLogProductionCanaryDryRun $productionCanaryDryRun,
    ): int {
        $requestedLimit = (int) $this->option('limit');
        $limit = max(1, min($requestedLimit, CrawlerLogAggregateDryRun::MAX_LIMIT));
        $fixture = (bool) $this->option('fixture');
        $source = $this->nullableOption('source');

        if ($fixture && $source !== null) {
            $this->emit([
                'runtime' => 'crawler_log_observe',
                'status' => 'blocked',
                'mode' => 'mode_selection',
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
                'parsed_line_count' => 0,
                'aggregate_row_count' => 0,
                'issues' => [
                    'fixture_and_source_are_mutually_exclusive',
                ],
                'privacy_transform_version' => CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION,
            ]);

            return self::FAILURE;
        }

        if ($source !== null) {
            $report = $productionCanaryDryRun->report(
                $source,
                $requestedLimit,
                $this->nullableOption('approval-phrase'),
                (bool) $this->option('dry-run'),
                (bool) $this->option('no-write'),
            );
            $this->emit($report);

            return ($report['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
        }

        if (! $fixture) {
            $this->emit([
                'runtime' => 'crawler_log_observe',
                'status' => 'blocked',
                'mode' => 'mode_selection',
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
                'parsed_line_count' => 0,
                'aggregate_row_count' => 0,
                'issues' => [
                    'fixture_or_source_required',
                    'single_source_canary_requires_explicit_source',
                ],
                'privacy_transform_version' => CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION,
            ]);

            return self::FAILURE;
        }

        $fixturePath = base_path('tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log');
        $lines = file($fixturePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            $this->emit([
                'runtime' => 'crawler_log_observe',
                'status' => 'blocked',
                'mode' => 'synthetic_fixture_aggregate_dry_run',
                'fixture_only' => true,
                'dry_run' => true,
                'no_write' => true,
                'writes_attempted' => false,
                'writes_committed' => false,
                'production_log_read_attempted' => false,
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
                'raw_persistence' => false,
                'parsed_line_count' => 0,
                'aggregate_row_count' => 0,
                'issues' => ['fixture_unavailable'],
            ]);

            return self::FAILURE;
        }

        $report = $aggregateDryRun->report($lines, $limit);
        $this->emit($report);

        return self::SUCCESS;
    }

    private function nullableOption(string $key): ?string
    {
        $value = $this->option($key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ([
            'status',
            'dry_run',
            'no_write',
            'writes_attempted',
            'writes_committed',
            'production_log_read_attempted',
            'external_calls_attempted',
            'search_submission_attempted',
            'raw_persistence',
            'parsed_line_count',
            'aggregate_row_count',
        ] as $key) {
            $this->line($key.'='.$this->stringValue($payload[$key] ?? null));
        }
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
