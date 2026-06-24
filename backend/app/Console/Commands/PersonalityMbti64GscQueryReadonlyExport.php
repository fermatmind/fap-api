<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\GscReadonlyLiveAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PersonalityMbti64GscQueryReadonlyExport extends Command
{
    private const TASK = 'MBTI64-GSC-API-READONLY-INTEGRATION-01';

    private const FORBIDDEN_PATTERNS = [
        '/results',
        '/orders',
        '/share',
        '/pay',
        '/payment',
        '/history',
        '/private',
        '/account',
        'token=',
        'session=',
        'result_id=',
        'report_id=',
        'order_no=',
    ];

    protected $signature = 'personality:mbti64-gsc-query-readonly-export
        {--targets= : JSON or CSV target packet containing MBTI64 canonical URLs or paths}
        {--start-date= : Required for --execute-live-read, YYYY-MM-DD}
        {--end-date= : Required for --execute-live-read, YYYY-MM-DD}
        {--limit-per-url=25 : Per-URL GSC row limit, bounded 1..250}
        {--dry-run : Required; confirms no writes/imports/queue/submission}
        {--execute-live-read : Explicitly call the read-only GSC Search Analytics API}
        {--json : Emit JSON summary}
        {--output= : Optional JSON artifact path}
        {--csv-output= : Optional CSV artifact path for fap-web importer}';

    protected $description = 'Export MBTI64 public personality GSC query rows through the read-only GSC adapter.';

    public function handle(GscReadonlyLiveAdapter $adapter): int
    {
        try {
            $summary = $this->buildSummary($adapter);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        }

        $this->writeJsonOutput($summary);
        $this->writeCsvOutput($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(GscReadonlyLiveAdapter $adapter): array
    {
        if (! (bool) $this->option('dry-run')) {
            throw new RuntimeException('--dry-run is required.');
        }

        $executeLiveRead = (bool) $this->option('execute-live-read');
        $targets = $this->loadTargets();
        $startDate = $this->dateOption('start-date', $executeLiveRead);
        $endDate = $this->dateOption('end-date', $executeLiveRead);
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw new RuntimeException('start date must be before or equal to end date.');
        }

        $limit = $this->limitOption();
        $preflight = $adapter->preflight([
            'allow_external_api_calls' => $executeLiveRead,
        ]);

        $results = [];
        $rows = [];
        $issues = [];
        if ($executeLiveRead) {
            foreach ($targets as $target) {
                $request = [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['query', 'page'],
                    'rowLimit' => $limit,
                    'dimensionFilterGroups' => [[
                        'filters' => [[
                            'dimension' => 'page',
                            'operator' => 'equals',
                            'expression' => $target['canonical_url'],
                        ]],
                    ]],
                ];
                $result = $adapter->fetchSearchAnalyticsRows($request, [
                    'allow_external_api_calls' => true,
                    'execute_live_read' => true,
                ]);

                $targetRows = array_values(array_filter(
                    (array) ($result['rows'] ?? []),
                    fn (mixed $row): bool => is_array($row) && ($row['page'] ?? null) === $target['canonical_url']
                ));

                foreach ($targetRows as $row) {
                    $rows[] = [
                        'target_url' => $target['canonical_url'],
                        'path' => $target['path'],
                        'query' => (string) ($row['query'] ?? ''),
                        'query_hash' => hash('sha256', (string) ($row['query'] ?? '')),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'ctr' => $row['ctr'] ?? null,
                        'position' => $row['position'] ?? null,
                        'date_range' => [
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ],
                        'source' => 'gsc_searchanalytics_readonly_api',
                    ];
                }

                $resultIssues = array_values(array_map('strval', (array) ($result['issues'] ?? [])));
                $issues = [...$issues, ...$resultIssues];
                $results[] = [
                    'target_url' => $target['canonical_url'],
                    'status' => (string) ($result['status'] ?? 'unknown'),
                    'rows_seen' => count($targetRows),
                    'external_calls_attempted' => (bool) ($result['external_calls_attempted'] ?? false),
                    'writes_attempted' => (bool) ($result['writes_attempted'] ?? false),
                    'issues' => $resultIssues,
                ];
            }
        } else {
            foreach ($targets as $target) {
                $results[] = [
                    'target_url' => $target['canonical_url'],
                    'status' => 'preflight_only',
                    'rows_seen' => 0,
                    'external_calls_attempted' => false,
                    'writes_attempted' => false,
                    'issues' => [],
                ];
            }
        }

        $writesAttempted = false;
        $externalCallsAttempted = in_array(true, array_map(
            static fn (array $result): bool => (bool) ($result['external_calls_attempted'] ?? false),
            $results
        ), true);

        return [
            'ok' => ! $writesAttempted && ($executeLiveRead ? $issues === [] : true),
            'task' => self::TASK,
            'schema_version' => 'mbti64-gsc-query-readonly-export.v1',
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'mode' => $executeLiveRead ? 'live_read' : 'preflight_only',
            'dry_run' => true,
            'no_write' => true,
            'execute_live_read' => $executeLiveRead,
            'target_count' => count($targets),
            'targets' => $targets,
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'limit_per_url' => $limit,
            'preflight' => $preflight,
            'per_url_results' => $results,
            'query_rows' => $rows,
            'query_row_count' => count($rows),
            'issues' => array_values(array_unique($issues)),
            'safety_boundary' => [
                'writes_attempted' => false,
                'writes_committed' => false,
                'cms_mutation_attempted' => false,
                'enqueue_attempted' => false,
                'search_submission_attempted' => false,
                'indexing_request_attempted' => false,
                'sitemap_llms_mutation_attempted' => false,
                'external_calls_attempted' => $externalCallsAttempted,
            ],
        ];
    }

    /**
     * @return list<array{canonical_url:string,path:string}>
     */
    private function loadTargets(): array
    {
        $path = trim((string) $this->option('targets'));
        if ($path === '') {
            throw new RuntimeException('--targets is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('targets file not found: '.$resolved);
        }

        $raw = (string) File::get($resolved);
        $values = str_ends_with(strtolower($resolved), '.csv')
            ? $this->targetsFromCsv($raw)
            : $this->targetsFromJson($raw);

        $targets = [];
        foreach ($values as $value) {
            $target = $this->normalizeTarget($value);
            if ($target === null) {
                continue;
            }
            $this->assertPublicPersonalityTarget($target['canonical_url']);
            $targets[$target['canonical_url']] = $target;
        }

        if ($targets === []) {
            throw new RuntimeException('targets file did not contain any usable MBTI64 public personality URL.');
        }

        return array_values($targets);
    }

    /**
     * @return list<string>
     */
    private function targetsFromJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('targets JSON must be an object or array.');
        }

        $candidates = [];
        $this->collectTargetValues($decoded, $candidates);

        return $candidates;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $values
     */
    private function collectTargetValues(array $node, array &$values): void
    {
        foreach (['target_url', 'canonical_url', 'url', 'page', 'path'] as $key) {
            if (isset($node[$key]) && is_string($node[$key])) {
                $values[] = $node[$key];
            }
        }

        foreach ($node as $value) {
            if (is_string($value) && (str_starts_with($value, '/') || str_starts_with($value, 'https://'))) {
                $values[] = $value;
            } elseif (is_array($value)) {
                $this->collectTargetValues($value, $values);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function targetsFromCsv(string $raw): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $raw) ?: []));
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        $values = [];
        foreach ($lines as $line) {
            $row = str_getcsv((string) $line);
            $assoc = array_combine($header, $row);
            if (! is_array($assoc)) {
                continue;
            }
            foreach (['target_url', 'canonical_url', 'url', 'page', 'path'] as $key) {
                if (isset($assoc[$key]) && trim((string) $assoc[$key]) !== '') {
                    $values[] = trim((string) $assoc[$key]);
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * @return array{canonical_url:string,path:string}|null
     */
    private function normalizeTarget(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            $path = $value;
            $canonical = 'https://fermatmind.com'.$path;
        } else {
            $parts = parse_url($value);
            if (! is_array($parts) || ($parts['host'] ?? '') !== 'fermatmind.com') {
                return null;
            }
            $path = (string) ($parts['path'] ?? '');
            $canonical = 'https://fermatmind.com'.$path;
        }

        $path = rtrim($path, '/') ?: '/';

        return [
            'canonical_url' => 'https://fermatmind.com'.$path,
            'path' => $path,
        ];
    }

    private function assertPublicPersonalityTarget(string $canonicalUrl): void
    {
        $lower = strtolower($canonicalUrl);
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                throw new RuntimeException('forbidden private route target: '.$canonicalUrl);
            }
        }

        $path = (string) (parse_url($canonicalUrl, PHP_URL_PATH) ?: '');
        if (preg_match('#^/(en|zh)/personality/[a-z]{4}-(a|t)(-vs-[a-z]{4}-(a|t))?$#', $path) !== 1) {
            throw new RuntimeException('target is not an MBTI64 public personality URL: '.$canonicalUrl);
        }
    }

    private function dateOption(string $name, bool $required): ?string
    {
        $value = trim((string) $this->option($name));
        if ($value === '' && ! $required) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException('--'.$name.' must be YYYY-MM-DD.');
        }

        return $value;
    }

    private function limitOption(): int
    {
        $raw = trim((string) $this->option('limit-per-url'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException('--limit-per-url must be an integer.');
        }

        $limit = (int) $raw;
        if ($limit < 1 || $limit > 250) {
            throw new RuntimeException('--limit-per-url must be between 1 and 250.');
        }

        return $limit;
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $message): array
    {
        return [
            'ok' => false,
            'task' => self::TASK,
            'schema_version' => 'mbti64-gsc-query-readonly-export.v1',
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'error' => $message,
            'dry_run' => (bool) $this->option('dry-run'),
            'no_write' => true,
            'safety_boundary' => [
                'writes_attempted' => false,
                'writes_committed' => false,
                'cms_mutation_attempted' => false,
                'enqueue_attempted' => false,
                'search_submission_attempted' => false,
                'indexing_request_attempted' => false,
                'sitemap_llms_mutation_attempted' => false,
                'external_calls_attempted' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeJsonOutput(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || File::put($output, $encoded."\n") === false) {
            throw new RuntimeException('failed to write output artifact.');
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeCsvOutput(array $summary): void
    {
        $output = trim((string) $this->option('csv-output'));
        if ($output === '') {
            return;
        }

        $handle = fopen($output, 'w');
        if ($handle === false) {
            throw new RuntimeException('failed to write CSV artifact.');
        }

        fputcsv($handle, ['target_url', 'path', 'query', 'query_hash', 'clicks', 'impressions', 'ctr', 'position', 'start_date', 'end_date', 'source']);
        foreach ((array) ($summary['query_rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            fputcsv($handle, [
                $row['target_url'] ?? '',
                $row['path'] ?? '',
                $row['query'] ?? '',
                $row['query_hash'] ?? '',
                $row['clicks'] ?? 0,
                $row['impressions'] ?? 0,
                $row['ctr'] ?? '',
                $row['position'] ?? '',
                data_get($row, 'date_range.start_date'),
                data_get($row, 'date_range.end_date'),
                $row['source'] ?? '',
            ]);
        }

        fclose($handle);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('task='.self::TASK);
        $this->line('mode='.(string) ($summary['mode'] ?? 'failed'));
        $this->line('target_count='.(string) ($summary['target_count'] ?? 0));
        $this->line('query_row_count='.(string) ($summary['query_row_count'] ?? 0));
        $this->line('writes_attempted=0');
        $this->line('search_submission_attempted=0');
    }
}
