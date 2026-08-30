<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class GscReadModelSyncService
{
    // This imports Search Analytics observations only; it never submits URLs or indexing requests.
    /** @var list<int> */
    public const WINDOWS = [7, 28, 90];

    /** @var list<string> */
    public const SEARCH_TYPES = ['web', 'image', 'video', 'news'];

    public function __construct(
        private readonly GscReadonlyLiveAdapter $adapter,
        private readonly GscSearchAnalyticsRowNormalizer $normalizer,
        private readonly GscDataQualityGate $qualityGate,
        private readonly GscRunCloseoutSummarizer $closeoutSummarizer,
        private readonly CurrentPublicUrlAuthoritySource $currentPublicAuthority,
        private readonly PageFamilyClassifier $pageFamilyClassifier,
    ) {}

    /**
     * @param  list<string>  $searchTypes
     * @return array<string, mixed>
     */
    public function sync(
        int $windowDays = 28,
        array $searchTypes = ['web'],
        bool $fullWindow = false,
        string $triggerMode = 'manual',
    ): array {
        if (! in_array($windowDays, self::WINDOWS, true)) {
            return $this->blocked('unsupported_window');
        }

        $searchTypes = array_values(array_unique(array_intersect($searchTypes, self::SEARCH_TYPES)));
        if ($searchTypes === []) {
            return $this->blocked('unsupported_search_type');
        }
        $triggerMode = strtolower(trim($triggerMode));
        if (! in_array($triggerMode, ['manual', 'scheduled', 'rerun'], true)) {
            return $this->blocked('unsupported_trigger_mode');
        }

        $preflight = $this->adapter->preflight(['allow_external_api_calls' => true]);
        if (($preflight['status'] ?? 'blocked') !== 'ready') {
            return $this->blocked('gsc_disconnected', (array) ($preflight['issues'] ?? []), $preflight);
        }

        if (! (bool) config('seo_intel.write_enabled', false)) {
            return $this->blocked('seo_intel_write_disabled', [], $preflight);
        }

        $connectionName = (string) config('seo_intel.connection', 'seo_intel');
        $schema = Schema::connection($connectionName);
        foreach (['seo_gsc_daily', 'seo_urls', 'seo_url_entities', 'seo_gsc_sync_runs', 'seo_gsc_data_quality_queue'] as $table) {
            if (! $schema->hasTable($table)) {
                return $this->blocked('gsc_read_model_schema_missing', [$table], $preflight);
            }
        }

        $connection = DB::connection($connectionName);
        $lagDays = max(0, (int) config('seo_intel.gsc_backfill_lag_days', 3));
        $reportingTimezone = (string) config('seo_intel.gsc_reporting_timezone', 'America/Los_Angeles');
        $endDate = CarbonImmutable::now($reportingTimezone)->subDays($lagDays)->startOfDay();
        $requestedStartDate = $endDate->subDays($windowDays - 1);
        $startDate = $fullWindow
            ? $requestedStartDate
            : $this->incrementalStartDate(
                $connection,
                $requestedStartDate,
                $endDate,
                $windowDays,
                $searchTypes,
            );
        $readModelBefore = $this->closeoutSummarizer->readModelSnapshot(
            $connection,
            $requestedStartDate,
            $endDate,
            $searchTypes,
        );
        $runUid = (string) Str::uuid();
        $now = CarbonImmutable::now('UTC');

        $connection->table('seo_gsc_sync_runs')->insert([
            'sync_run_uid' => $runUid,
            'window_days' => $windowDays,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'search_types_json' => json_encode($searchTypes, JSON_THROW_ON_ERROR),
            'trigger_mode' => $triggerMode,
            'status' => 'running',
            'started_at' => $now->toDateTimeString(),
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

        try {
            [$rows, $pages, $failure] = $this->fetchRows($startDate, $endDate, $searchTypes);
            if ($failure !== null) {
                return $this->finishFailure($connection, $runUid, $failure, $pages, count($rows), $preflight);
            }

            if ($rows === []) {
                return $this->finishFailure($connection, $runUid, 'gsc_empty_response', $pages, 0, $preflight);
            }

            $quality = $this->qualityGate->evaluate($rows);
            if (($quality['status'] ?? 'blocked') !== 'pass') {
                return $this->finishFailure(
                    $connection,
                    $runUid,
                    'gsc_data_quality_gate_failed',
                    $pages,
                    count($rows),
                    $preflight,
                    $quality,
                );
            }

            [$upserted, $excluded] = $this->persistRows($connection, $runUid, $rows);
            $closeout = $this->closeoutSummarizer->summarize(
                $connection,
                $rows,
                $requestedStartDate,
                $endDate,
                $searchTypes,
                $readModelBefore,
            );
            $finished = CarbonImmutable::now('UTC');
            $connection->table('seo_gsc_sync_runs')->where('sync_run_uid', $runUid)->update([
                'status' => 'success',
                'pages_fetched' => $pages,
                'rows_seen' => count($rows),
                'rows_upserted' => $upserted,
                'unmapped_rows' => 0,
                'quality_gate_json' => json_encode($quality, JSON_THROW_ON_ERROR),
                'finished_at' => $finished->toDateTimeString(),
                'updated_at' => $finished->toDateTimeString(),
            ]);

            $receipt = [
                'status' => 'success',
                'sync_run_uid' => $runUid,
                'window_days' => $windowDays,
                'fetch_mode' => $fullWindow ? 'full_window' : 'incremental',
                'requested_start_date' => $requestedStartDate->toDateString(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'search_types' => $searchTypes,
                'trigger_mode' => $triggerMode,
                'reporting_timezone' => $reportingTimezone,
                'pages_fetched' => $pages,
                'rows_seen' => count($rows),
                'rows_upserted' => $upserted,
                'unmapped_rows' => 0,
                'mapped_rows' => $upserted,
                'excluded_non_authority_rows' => $excluded,
                'quality_gate' => $quality,
                ...$closeout,
                'duplicate_natural_keys' => (int) data_get(
                    $closeout,
                    'gsc_data_quality.overlap_comparison.natural_key_duplicate_count',
                    0,
                ),
                'data_max_date' => data_get($closeout, 'gsc_data_quality.fetched.max_report_date'),
                'data_lag_days' => data_get($closeout, 'gsc_data_quality.fetched.latest_data_lag_days'),
                'read_only_gsc' => true,
                'search_submission_allowed' => false,
                'restricted_egress' => $preflight['restricted_egress'] ?? ['status' => 'blocked'],
                'application_sha' => $this->releaseSha(),
                'workflow_sha' => $this->releaseSha(),
                'active_production_sha' => $this->releaseSha(),
                'property_hash' => $preflight['property_hash'] ?? null,
            ];
            $connection->table('seo_gsc_sync_runs')->where('sync_run_uid', $runUid)->update([
                'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => $finished->toDateTimeString(),
            ]);

            return $receipt;
        } catch (Throwable) {
            return $this->finishFailure($connection, $runUid, 'gsc_sync_internal_failure', 0, 0, $preflight);
        }
    }

    private function releaseSha(): ?string
    {
        $candidates = [
            trim((string) config('app.git_sha', '')),
            is_file(dirname(base_path()).'/REVISION') ? trim((string) file_get_contents(dirname(base_path()).'/REVISION')) : '',
        ];
        foreach ($candidates as $candidate) {
            if (preg_match('/^[a-f0-9]{40}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $searchTypes
     * @return array{0:list<array<string,mixed>>,1:int,2:?string}
     */
    private function fetchRows(CarbonImmutable $startDate, CarbonImmutable $endDate, array $searchTypes): array
    {
        $rows = [];
        $pages = 0;
        $rowLimit = max(1, (int) config('seo_intel.gsc_readonly_adapter.max_limit', 250));
        $maxPages = max(1, (int) config('seo_intel.gsc_sync.max_pages_per_run', 5000));

        for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
            foreach ($searchTypes as $searchType) {
                $startRow = 0;
                do {
                    if ($pages >= $maxPages) {
                        return [$rows, $pages, 'gsc_pagination_limit_exceeded'];
                    }

                    $result = $this->adapter->fetchSearchAnalyticsRows([
                        'startDate' => $date->toDateString(),
                        'endDate' => $date->toDateString(),
                        'dimensions' => ['query', 'page', 'device', 'country'],
                        'type' => $searchType,
                        'rowLimit' => $rowLimit,
                        'startRow' => $startRow,
                    ], [
                        'allow_external_api_calls' => true,
                        'execute_live_read' => true,
                    ]);
                    $pages++;

                    if (($result['status'] ?? 'blocked') !== 'success') {
                        return [
                            $rows,
                            $pages,
                            (string) ((array) ($result['issues'] ?? ['gsc_searchanalytics_request_failed']))[0],
                        ];
                    }

                    foreach ((array) ($result['rows'] ?? []) as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $rows[] = $this->normalizer->normalize($row + [
                            'date' => $date->toDateString(),
                            'data_origin' => 'live_gsc_api',
                        ]);
                    }

                    $nextStartRow = $result['next_start_row'] ?? null;
                    $startRow = is_int($nextStartRow) ? $nextStartRow : -1;
                } while ($startRow >= 0);
            }
        }

        return [$rows, $pages, null];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{0:int,1:int}
     */
    private function persistRows(ConnectionInterface $connection, string $runUid, array $rows): array
    {
        $schema = $connection->getSchemaBuilder();
        $hmacColumnsAvailable = $schema->hasColumn('seo_gsc_daily', 'query_hmac')
            && $schema->hasColumn('seo_gsc_daily', 'query_hmac_key_version');
        $hashes = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['canonical_url_hash'] ?? null) ? $row['canonical_url_hash'] : null,
            $rows,
        ))));
        $truth = $connection->table('seo_urls')
            ->whereIn('canonical_url_hash', $hashes)
            ->where('indexability_state', 'indexable')
            ->where('is_private_flow', false)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('seo_url_entities')
                    ->whereColumn('seo_url_entities.canonical_url_hash', 'seo_urls.canonical_url_hash')
                    ->whereColumn('seo_url_entities.locale', 'seo_urls.locale')
                    ->whereColumn('seo_url_entities.page_entity_type', 'seo_urls.page_entity_type')
                    ->whereColumn('seo_url_entities.entity_id_or_slug', 'seo_urls.entity_id_or_slug')
                    ->where('seo_url_entities.binding_status', 'current');
            })
            ->orderBy('id')
            ->get(['id', 'canonical_url_hash', 'canonical_url', 'locale'])
            ->keyBy('canonical_url_hash');
        $authority = $this->currentAuthorityIndex();
        $now = CarbonImmutable::now('UTC')->toDateTimeString();
        $payloads = [];
        $qualityRows = [];

        foreach ($rows as $row) {
            if (! $hmacColumnsAvailable) {
                unset($row['query_hmac'], $row['query_hmac_key_version']);
            }
            $sourceHash = (string) ($row['canonical_url_hash'] ?? '');
            $truthRow = $truth->get($sourceHash);
            $authorityRow = $truthRow === null ? ($authority['variants'][$sourceHash] ?? null) : null;
            $mapped = $truthRow !== null || is_array($authorityRow);
            if (! $mapped) {
                if ($sourceHash !== '') {
                    $qualityRows[] = [
                        'sync_run_uid' => $runUid,
                        'report_date' => (string) $row['report_date'],
                        'canonical_url_hash' => $sourceHash,
                        'issue_code' => 'canonical_url_not_in_current_public_authority',
                        'status' => 'open',
                        'details_json' => json_encode([
                            'source' => 'gsc',
                            'search_type' => $row['search_type'] ?? null,
                            'disposition' => 'excluded_from_primary_readmodel',
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                continue;
            }

            $canonicalUrl = $truthRow !== null
                ? (string) $truthRow->canonical_url
                : (string) $authorityRow['canonical_url'];
            $canonicalHash = hash('sha256', $canonicalUrl);
            $locale = $truthRow !== null
                ? (string) ($truthRow->locale ?? '')
                : (string) $authorityRow['locale'];
            $pageFamily = $truthRow !== null
                ? $this->pageFamily($canonicalUrl, $locale)
                : (string) $authorityRow['page_family'];
            $sourceAuthority = $truthRow !== null
                ? 'persisted_url_truth'
                : (string) $authorityRow['source_authority'];
            $metadata = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
            $metadata['mapping_authority'] = $truthRow !== null
                ? 'persisted_url_truth_current_binding'
                : 'current_public_url_authority_read_only';
            $metadata['authority_revision'] = $authority['revision'];
            $metadata['page_family'] = $pageFamily;
            $metadata['source_authority'] = $sourceAuthority;
            $row['canonical_url_hash'] = $canonicalHash;
            $row['canonical_url'] = $canonicalUrl;
            $row['locale'] = $locale;
            $row['metadata_json'] = $metadata;
            $payloads[] = [
                ...$row,
                'url_truth_id' => $truthRow !== null ? (int) $truthRow->id : null,
                'mapping_state' => 'mapped',
                'sync_run_uid' => $runUid,
                'idempotency_key' => $this->idempotencyKey($row),
                'collected_at' => $now,
                'metadata_json' => json_encode($row['metadata_json'] ?? [], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];

        }

        $connection->transaction(function () use ($connection, $payloads, $qualityRows, $hmacColumnsAvailable): void {
            $updateColumns = [
                'url_truth_id', 'canonical_url', 'mapping_state', 'sync_run_uid', 'locale', 'clicks', 'impressions',
                'ctr_ppm', 'average_position_milli', 'data_state', 'collected_at', 'metadata_json', 'updated_at',
            ];
            if ($hmacColumnsAvailable) {
                array_splice($updateColumns, 5, 0, ['query_hmac', 'query_hmac_key_version']);
            }
            foreach (array_chunk($payloads, 500) as $chunk) {
                $connection->table('seo_gsc_daily')->upsert(
                    $chunk,
                    ['idempotency_key'],
                    $updateColumns,
                );
            }
            foreach (array_chunk($qualityRows, 500) as $chunk) {
                $connection->table('seo_gsc_data_quality_queue')->insertOrIgnore($chunk);
            }
        });

        return [count($payloads), count($qualityRows)];
    }

    /** @return array{variants:array<string,array<string,string>>,revision:string} */
    private function currentAuthorityIndex(): array
    {
        $variants = [];
        $canonicalHashes = [];

        foreach ($this->currentPublicAuthority->candidates() as $record) {
            if ($record->isPrivateFlow || $record->indexabilityState !== 'indexable') {
                continue;
            }
            $pageFamily = $this->pageFamily(
                $record->canonicalUrl,
                $record->locale,
                $record->pageEntityType,
                $record->sourceAuthority,
                $record->entitySource,
                $record->authorityStatus,
            );
            if ($pageFamily === '') {
                continue;
            }
            $canonicalHash = $record->canonicalUrlHash();
            $canonicalHashes[$canonicalHash] = true;
            $mapped = [
                'canonical_url' => $record->canonicalUrl,
                'locale' => $record->locale,
                'page_family' => $pageFamily,
                'source_authority' => $record->sourceAuthority,
            ];
            foreach ($this->canonicalVariants($record->canonicalUrl) as $variant) {
                $variants[hash('sha256', $variant)] ??= $mapped;
            }
        }

        $revisionParts = array_keys($canonicalHashes);
        sort($revisionParts, SORT_STRING);

        return [
            'variants' => $variants,
            'revision' => hash('sha256', json_encode($revisionParts, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return list<string> */
    private function canonicalVariants(string $canonicalUrl): array
    {
        $parts = parse_url($canonicalUrl);
        if (! is_array($parts) || ! is_string($parts['path'] ?? null)) {
            return [];
        }
        $path = rtrim((string) $parts['path'], '/');
        $path = $path === '' ? '/' : $path;
        $paths = [$path];
        if (str_starts_with($path, '/zh/')) {
            $paths[] = preg_replace('#^/zh/#', '/zh-cn/', $path) ?: $path;
            $paths[] = preg_replace('#^/zh/#', '/zh_CN/', $path) ?: $path;
        } elseif (str_starts_with($path, '/en/')) {
            $paths[] = preg_replace('#^/en/#', '/en-us/', $path) ?: $path;
            $paths[] = preg_replace('#^/en/#', '/en_US/', $path) ?: $path;
        }

        $variants = [];
        foreach (array_unique($paths) as $variantPath) {
            foreach (['https://fermatmind.com', 'https://www.fermatmind.com', 'http://fermatmind.com', 'http://www.fermatmind.com'] as $host) {
                $variants[] = $host.$variantPath;
                if ($variantPath !== '/') {
                    $variants[] = $host.$variantPath.'/';
                }
            }
        }

        return array_values(array_unique($variants));
    }

    private function pageFamily(
        string $canonicalUrl,
        string $locale,
        string $pageEntityType = '',
        string $sourceAuthority = 'current_public_url_authority',
        string $entitySource = 'backend_authority',
        string $authorityStatus = 'published_approved',
    ): string {
        $classification = $this->pageFamilyClassifier->classify([
            'canonical_url' => $canonicalUrl,
            'locale' => $locale,
            'page_entity_type' => $pageEntityType,
            'source_authority' => $sourceAuthority,
            'entity_source' => $entitySource,
            'authority_status' => $authorityStatus,
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
        ]);

        return ($classification['classification_status'] ?? null) === 'classified'
            ? (string) ($classification['family_id'] ?? '')
            : '';
    }

    private function incrementalStartDate(
        ConnectionInterface $connection,
        CarbonImmutable $requestedStartDate,
        CarbonImmutable $endDate,
        int $windowDays,
        array $searchTypes,
    ): CarbonImmutable {
        $successfulRuns = $connection->table('seo_gsc_sync_runs')
            ->where('status', 'success')
            ->where('window_days', '>=', $windowDays)
            ->orderByDesc('end_date')
            ->get(['end_date', 'search_types_json']);
        $lastEndDate = null;

        foreach ($successfulRuns as $run) {
            $coveredTypes = json_decode((string) $run->search_types_json, true);
            if (! is_array($coveredTypes) || array_diff($searchTypes, array_map('strval', $coveredTypes)) !== []) {
                continue;
            }

            $lastEndDate = (string) $run->end_date;
            break;
        }

        if ($lastEndDate === null || $lastEndDate === '') {
            return $requestedStartDate;
        }

        $nextDate = CarbonImmutable::parse($lastEndDate, 'UTC')->addDay()->startOfDay();

        return $nextDate->gt($endDate) ? $endDate : $nextDate->max($requestedStartDate);
    }

    /** @return array<string,mixed> */
    private function finishFailure(
        ConnectionInterface $connection,
        string $runUid,
        string $failureCode,
        int $pages,
        int $rows,
        array $preflight,
        ?array $quality = null,
    ): array {
        $finished = CarbonImmutable::now('UTC');
        $connection->table('seo_gsc_sync_runs')->where('sync_run_uid', $runUid)->update([
            'status' => $failureCode === 'gsc_data_quality_gate_failed' ? 'quality_failed' : 'failed',
            'pages_fetched' => $pages,
            'rows_seen' => $rows,
            'failure_code' => $failureCode,
            'quality_gate_json' => $quality === null ? null : json_encode($quality, JSON_THROW_ON_ERROR),
            'finished_at' => $finished->toDateTimeString(),
            'updated_at' => $finished->toDateTimeString(),
        ]);

        return $this->blocked($failureCode, [], $preflight, [
            'sync_run_uid' => $runUid,
            'pages_fetched' => $pages,
            'rows_seen' => $rows,
            'quality_gate' => $quality,
        ]);
    }

    /** @return array<string,mixed> */
    private function blocked(string $issue, array $details = [], array $preflight = [], array $extra = []): array
    {
        return [
            ...$extra,
            'status' => 'blocked',
            'issue' => $issue,
            'details' => $details,
            'preflight' => $preflight,
            'rows_upserted' => 0,
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
        ];
    }

    /** @param array<string,mixed> $row */
    private function idempotencyKey(array $row): string
    {
        return hash('sha256', json_encode([
            (string) ($row['report_date'] ?? ''),
            (string) ($row['canonical_url_hash'] ?? ''),
            (string) ($row['query_hash'] ?? ''),
            (string) ($row['source_engine'] ?? 'google'),
            (string) ($row['device'] ?? ''),
            (string) ($row['country'] ?? ''),
            (string) ($row['search_type'] ?? ''),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
