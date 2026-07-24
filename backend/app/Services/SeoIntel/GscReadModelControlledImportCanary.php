<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GscReadModelControlledImportCanary
{
    public const SCHEMA_VERSION = 'gsc-readmodel-controlled-import-canary.v1';

    public const TASK = 'SEO-GSC-READMODEL-CONTROLLED-IMPORT-CANARY-01';

    public const TARGET_TABLE = 'seo_gsc_daily';

    public const BACKFILL_SCHEMA_VERSION = 'gsc-readmodel-bounded-backfill.v1';

    public const BACKFILL_TASK = 'SEO-10K-GSC-BOUNDED-BACKFILL-01';

    public const BACKFILL_MAX_ROWS = 10000;

    public const BACKFILL_MAX_BATCH_SIZE = 1000;

    /**
     * @var list<string>
     */
    public const BACKFILL_COHORTS = ['page', 'query', 'query-page'];

    private const BACKFILL_SOURCE_SCHEMA = 'gsc-hk-sidecar-runner-wrapper.v1';

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 10;

    public function __construct(private readonly GscReadModelArtifactDryRunImporter $dryRunImporter) {}

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function plan(array $artifact, string $artifactSha256, int $limit): array
    {
        $issues = $this->limitIssues($limit);
        $preview = $this->dryRunImporter->preview($artifact, $limit);

        if (($preview['ok'] ?? false) !== true) {
            $issues[] = 'dry_run_importer_validation_failed';
        }

        $issues = array_values(array_unique($issues));
        $ok = $issues === [];
        $rows = $ok ? array_slice((array) ($preview['preview_rows'] ?? []), 0, $limit) : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => $ok ? 'success' : 'blocked',
            'mode' => 'dry_run_canary_plan',
            'ok' => $ok,
            'dry_run' => true,
            'execute' => false,
            'would_write' => $ok,
            'writes_attempted' => false,
            'writes_committed' => false,
            'target_connection' => (string) config('seo_intel.connection', 'seo_intel'),
            'target_table' => self::TARGET_TABLE,
            'artifact_sha256' => $artifactSha256,
            'required_confirmation_phrase' => $this->confirmationPhrase($artifactSha256, $limit),
            'max_rows_per_execution' => self::MAX_LIMIT,
            'rows_previewed' => count($rows),
            'rows_would_insert' => $ok ? count($rows) : 0,
            'rows_inserted' => 0,
            'rows_skipped_existing' => 0,
            'rows_failed' => [],
            'write_boundary' => $this->writeBoundary(writeAllowed: false),
            'data_origin' => $preview['data_origin'] ?? null,
            'data_quality_gate' => $preview['data_quality_gate'] ?? null,
            'date_window' => $preview['date_window'] ?? null,
            'preview_rows' => $rows,
            'dry_run_importer_errors' => (array) ($preview['errors'] ?? []),
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function execute(
        array $artifact,
        string $artifactSha256,
        int $limit,
        ?string $confirmedArtifactSha256,
        ?string $confirmedWritePhrase,
    ): array {
        $plan = $this->plan($artifact, $artifactSha256, $limit);
        $issues = (array) ($plan['issues'] ?? []);

        if ($confirmedArtifactSha256 === null || ! hash_equals($artifactSha256, $confirmedArtifactSha256)) {
            $issues[] = 'artifact_sha256_confirmation_required';
        }

        $expectedConfirmation = $this->confirmationPhrase($artifactSha256, $limit);
        if ($confirmedWritePhrase === null || ! hash_equals($expectedConfirmation, $confirmedWritePhrase)) {
            $issues[] = 'exact_write_confirmation_required';
        }

        $issues = array_values(array_unique($issues));
        if ($issues !== []) {
            return [
                ...$plan,
                'status' => 'blocked',
                'mode' => 'canary_execute_blocked',
                'ok' => false,
                'dry_run' => false,
                'execute' => true,
                'would_write' => false,
                'writes_attempted' => false,
                'writes_committed' => false,
                'rows_inserted' => 0,
                'rows_skipped_existing' => 0,
                'rows_failed' => [],
                'write_boundary' => $this->writeBoundary(writeAllowed: false),
                'issues' => $issues,
                'negative_guarantees' => $this->negativeGuarantees(),
            ];
        }

        $rows = array_values(array_filter((array) ($plan['preview_rows'] ?? []), 'is_array'));
        if ($rows === []) {
            return [
                ...$plan,
                'status' => 'blocked',
                'mode' => 'canary_execute_blocked',
                'ok' => false,
                'dry_run' => false,
                'execute' => true,
                'would_write' => false,
                'writes_attempted' => false,
                'writes_committed' => false,
                'rows_inserted' => 0,
                'rows_skipped_existing' => 0,
                'rows_failed' => [],
                'write_boundary' => $this->writeBoundary(writeAllowed: false),
                'issues' => ['preview_rows_required'],
                'negative_guarantees' => $this->negativeGuarantees(),
            ];
        }

        $connection = (string) config('seo_intel.connection', 'seo_intel');
        $query = DB::connection($connection)->table(self::TARGET_TABLE);
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($this->matchingRowExists($connection, $row)) {
                $skipped++;

                continue;
            }

            $now = Carbon::now('UTC')->toDateTimeString();
            $query->insert($this->insertPayload($row, $artifactSha256, $now));
            $inserted++;
        }

        return [
            ...$plan,
            'status' => 'success',
            'mode' => 'canary_execute',
            'ok' => true,
            'dry_run' => false,
            'execute' => true,
            'would_write' => false,
            'writes_attempted' => true,
            'writes_committed' => $inserted > 0,
            'rows_would_insert' => 0,
            'rows_inserted' => $inserted,
            'rows_skipped_existing' => $skipped,
            'rows_failed' => [],
            'write_boundary' => $this->writeBoundary(writeAllowed: true),
            'issues' => [],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function planBackfill(
        array $artifact,
        string $artifactSha256,
        string $cohort,
        int $batchSize,
        int $hardMaxRows,
        string $resumeKey,
        ?string $cursor = null,
        bool $reset = false,
    ): array {
        $issues = $this->backfillRequestIssues(
            $artifact,
            $artifactSha256,
            $cohort,
            $batchSize,
            $hardMaxRows,
            $resumeKey,
            $cursor,
            $reset,
        );
        $preview = $this->dryRunImporter->preview($artifact, self::BACKFILL_MAX_ROWS);
        if (($preview['ok'] ?? false) !== true) {
            $issues[] = 'artifact_validation_failed';
        }

        $resumeKeySha256 = hash('sha256', $resumeKey);
        $offset = 0;
        if ($issues === [] && $cursor !== null && ! $reset) {
            [$offset, $cursorIssues] = $this->decodeBackfillCursor(
                $cursor,
                $artifactSha256,
                $cohort,
                $resumeKeySha256,
                $hardMaxRows,
            );
            $issues = [...$issues, ...$cursorIssues];
        }

        $issues = array_values(array_unique($issues));
        $rows = [];
        if ($issues === []) {
            $rows = array_values(array_filter((array) ($preview['preview_rows'] ?? []), 'is_array'));
            $rows = $this->stableBackfillRows($rows, $cohort);
        }

        $availableRows = min(count($rows), $hardMaxRows);
        if ($issues === [] && $offset > $availableRows) {
            $issues[] = 'cursor_offset_out_of_range';
        }

        $ok = $issues === [];
        $batchRows = $ok
            ? array_slice($rows, $offset, min($batchSize, max(0, $availableRows - $offset)))
            : [];
        $batchKeys = array_map(fn (array $row): string => $this->idempotencyKey($row), $batchRows);
        $nextOffset = $offset + count($batchRows);
        $hasMore = $ok && $nextOffset < $availableRows;
        $binding = $this->backfillBinding(
            $artifactSha256,
            $cohort,
            $resumeKeySha256,
            $hardMaxRows,
        );

        return [
            'schema_version' => self::BACKFILL_SCHEMA_VERSION,
            'task' => self::BACKFILL_TASK,
            'status' => $ok ? 'success' : 'blocked',
            'mode' => 'dry_run_backfill_plan',
            'ok' => $ok,
            'dry_run' => true,
            'execute' => false,
            'would_write' => $ok && $batchRows !== [],
            'writes_attempted' => false,
            'writes_committed' => false,
            'target_connection' => (string) config('seo_intel.connection', 'seo_intel'),
            'target_table' => self::TARGET_TABLE,
            'artifact_sha256' => $artifactSha256,
            'artifact_schema' => $artifact['schema_version'] ?? null,
            'data_origin' => $preview['data_origin'] ?? null,
            'cohort' => $cohort,
            'batch_size' => $batchSize,
            'hard_max_rows' => $hardMaxRows,
            'max_supported_rows' => self::BACKFILL_MAX_ROWS,
            'resume_key_sha256' => $resumeKeySha256,
            'reset' => $reset,
            'cursor_offset' => $offset,
            'rows_available' => $availableRows,
            'rows_deferred_by_hard_max' => max(0, count($rows) - $availableRows),
            'rows_in_batch' => count($batchRows),
            'rows_would_insert' => count($batchRows),
            'rows_inserted' => 0,
            'rows_skipped_existing' => 0,
            'rows_failed' => [],
            'batch_idempotency_key' => hash('sha256', json_encode([
                $binding,
                $offset,
                $batchKeys,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'next_cursor' => $hasMore
                ? $this->encodeBackfillCursor(
                    $artifactSha256,
                    $cohort,
                    $resumeKeySha256,
                    $hardMaxRows,
                    $nextOffset,
                )
                : null,
            'has_more' => $hasMore,
            'complete' => $ok && ! $hasMore,
            'required_confirmation_phrase' => $this->backfillConfirmationPhrase(
                $artifactSha256,
                $cohort,
                $batchSize,
                $hardMaxRows,
                $resumeKeySha256,
            ),
            'readback_receipt' => $this->emptyBackfillReadbackReceipt(),
            'partial_failure_receipt' => null,
            'batch_rows' => $batchRows,
            'issues' => $issues,
            'negative_guarantees' => $this->backfillNegativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function executeBackfill(
        array $artifact,
        string $artifactSha256,
        string $cohort,
        int $batchSize,
        int $hardMaxRows,
        string $resumeKey,
        ?string $cursor,
        bool $reset,
        ?string $confirmedArtifactSha256,
        ?string $confirmedWritePhrase,
        bool $production,
        bool $confirmProductionWrite,
    ): array {
        $plan = $this->planBackfill(
            $artifact,
            $artifactSha256,
            $cohort,
            $batchSize,
            $hardMaxRows,
            $resumeKey,
            $cursor,
            $reset,
        );
        $issues = (array) ($plan['issues'] ?? []);
        if ($confirmedArtifactSha256 === null || ! hash_equals($artifactSha256, $confirmedArtifactSha256)) {
            $issues[] = 'artifact_sha256_confirmation_required';
        }

        $expectedPhrase = $this->backfillConfirmationPhrase(
            $artifactSha256,
            $cohort,
            $batchSize,
            $hardMaxRows,
            hash('sha256', $resumeKey),
        );
        if ($confirmedWritePhrase === null || ! hash_equals($expectedPhrase, $confirmedWritePhrase)) {
            $issues[] = 'exact_write_confirmation_required';
        }
        if ($production && ! $confirmProductionWrite) {
            $issues[] = 'production_write_confirmation_required';
        }

        $issues = array_values(array_unique($issues));
        if ($issues !== []) {
            return [
                ...$plan,
                'status' => 'blocked',
                'mode' => 'backfill_execute_blocked',
                'ok' => false,
                'dry_run' => false,
                'execute' => true,
                'would_write' => false,
                'writes_attempted' => false,
                'writes_committed' => false,
                'batch_rows' => [],
                'issues' => $issues,
            ];
        }

        $rows = array_values(array_filter((array) ($plan['batch_rows'] ?? []), 'is_array'));
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        $inserted = 0;
        $skipped = 0;
        $processedKeys = [];
        $failedRows = [];

        foreach ($rows as $index => $row) {
            $idempotencyKey = $this->idempotencyKey($row);
            $existingKey = $this->matchingIdempotencyKey($connection, $row);
            if ($existingKey !== null) {
                $processedKeys[] = $existingKey;
                $skipped++;

                continue;
            }

            try {
                $now = Carbon::now('UTC')->toDateTimeString();
                DB::connection($connection)
                    ->table(self::TARGET_TABLE)
                    ->insert($this->insertPayload(
                        $row,
                        $artifactSha256,
                        $now,
                        self::BACKFILL_TASK,
                    ));
            } catch (Throwable) {
                $existingKey = $this->matchingIdempotencyKey($connection, $row);
                if ($existingKey !== null) {
                    $processedKeys[] = $existingKey;
                    $skipped++;

                    continue;
                }

                $failedRows[] = [
                    'batch_index' => $index,
                    'idempotency_key' => $idempotencyKey,
                    'error_code' => 'database_write_failed',
                ];

                break;
            }

            $processedKeys[] = $idempotencyKey;
            $inserted++;
        }

        $processed = count($processedKeys);
        $nextOffset = (int) ($plan['cursor_offset'] ?? 0) + $processed;
        $availableRows = (int) ($plan['rows_available'] ?? 0);
        $hasMore = $nextOffset < $availableRows;
        $nextCursor = $hasMore
            ? $this->encodeBackfillCursor(
                $artifactSha256,
                $cohort,
                hash('sha256', $resumeKey),
                $hardMaxRows,
                $nextOffset,
            )
            : null;
        $readback = $this->backfillReadbackReceipt($connection, $processedKeys);
        $partialFailure = $failedRows === [] ? null : [
            'status' => 'partial_failure',
            'processed_before_failure' => $processed,
            'failed_rows' => $failedRows,
            'retry_cursor' => $nextCursor,
            'safe_to_retry' => true,
        ];

        return [
            ...$plan,
            'status' => $partialFailure === null ? 'success' : 'partial_failure',
            'mode' => 'backfill_execute',
            'ok' => $partialFailure === null,
            'dry_run' => false,
            'execute' => true,
            'would_write' => false,
            'writes_attempted' => $rows !== [],
            'writes_committed' => $inserted > 0,
            'rows_would_insert' => 0,
            'rows_inserted' => $inserted,
            'rows_skipped_existing' => $skipped,
            'rows_failed' => $failedRows,
            'rows_processed' => $processed,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'complete' => $partialFailure === null && ! $hasMore,
            'readback_receipt' => $readback,
            'partial_failure_receipt' => $partialFailure,
            'batch_rows' => [],
            'issues' => $partialFailure === null ? [] : ['database_write_failed'],
        ];
    }

    public function backfillConfirmationPhrase(
        string $artifactSha256,
        string $cohort,
        int $batchSize,
        int $hardMaxRows,
        string $resumeKeySha256,
    ): string {
        return sprintf(
            'I explicitly approve %s to write cohort %s in batches of at most %d rows, capped at %d rows, from artifact sha256 %s with resume key sha256 %s; no scheduler, queue, CMS, Search Channel, indexing, or sitemap submission.',
            self::BACKFILL_TASK,
            $cohort,
            $batchSize,
            $hardMaxRows,
            $artifactSha256,
            $resumeKeySha256,
        );
    }

    public function confirmationPhrase(string $artifactSha256, int $limit = self::MIN_LIMIT): string
    {
        return sprintf(
            'I explicitly approve %s to write at most %d rows to seo_gsc_daily from artifact sha256 %s; no scheduler, no queue, no CMS, no search, no indexing.',
            self::TASK,
            $limit,
            $artifactSha256,
        );
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function backfillRequestIssues(
        array $artifact,
        string $artifactSha256,
        string $cohort,
        int $batchSize,
        int $hardMaxRows,
        string $resumeKey,
        ?string $cursor,
        bool $reset,
    ): array {
        $issues = [];
        if (preg_match('/^[a-f0-9]{64}$/', $artifactSha256) !== 1) {
            $issues[] = 'artifact_sha256_invalid';
        }
        if (($artifact['schema_version'] ?? null) !== self::BACKFILL_SOURCE_SCHEMA) {
            $issues[] = 'artifact_schema_mismatch';
        }
        if (($artifact['mode'] ?? null) !== 'live-read') {
            $issues[] = 'artifact_mode_mismatch';
        }
        if (! in_array($cohort, self::BACKFILL_COHORTS, true)) {
            $issues[] = 'cohort_invalid';
        }
        if ($batchSize < 1 || $batchSize > self::BACKFILL_MAX_BATCH_SIZE) {
            $issues[] = 'batch_size_out_of_range';
        }
        if ($hardMaxRows < 1 || $hardMaxRows > self::BACKFILL_MAX_ROWS) {
            $issues[] = 'hard_max_rows_out_of_range';
        }
        if (trim($resumeKey) === '' || strlen($resumeKey) > 128) {
            $issues[] = 'resume_key_invalid';
        }
        if ($reset && $cursor !== null) {
            $issues[] = 'cursor_forbidden_with_reset';
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function stableBackfillRows(array $rows, string $cohort): array
    {
        $indexed = [];
        foreach ($rows as $index => $row) {
            $indexed[] = ['index' => $index, 'row' => $row];
        }

        usort($indexed, function (array $left, array $right) use ($cohort): int {
            return $this->backfillSortKey($left['row'], $cohort, (int) $left['index'])
                <=> $this->backfillSortKey($right['row'], $cohort, (int) $right['index']);
        });

        return array_values(array_map(static fn (array $item): array => $item['row'], $indexed));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int>
     */
    private function backfillSortKey(array $row, string $cohort, int $index): array
    {
        $page = $this->normalized($row['canonical_url_hash'] ?? '');
        $query = $this->normalized($row['query_hash'] ?? '');
        $prefix = match ($cohort) {
            'query' => [$query, $page],
            'query-page' => [hash('sha256', $query."\0".$page), $query, $page],
            default => [$page, $query],
        };

        return [
            ...$prefix,
            $this->normalized($row['report_date'] ?? ''),
            $this->normalized($row['device'] ?? ''),
            $this->normalized($row['country'] ?? ''),
            $this->normalized($row['search_type'] ?? ''),
            $index,
        ];
    }

    /**
     * @return array{0:int,1:list<string>}
     */
    private function decodeBackfillCursor(
        string $cursor,
        string $artifactSha256,
        string $cohort,
        string $resumeKeySha256,
        int $hardMaxRows,
    ): array {
        if (strlen($cursor) > 4096 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return [0, ['cursor_invalid']];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return [0, ['cursor_invalid']];
        }

        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [0, ['cursor_invalid']];
        }
        if (! is_array($payload)) {
            return [0, ['cursor_invalid']];
        }

        $binding = $this->backfillBinding(
            $artifactSha256,
            $cohort,
            $resumeKeySha256,
            $hardMaxRows,
        );
        $offset = $payload['offset'] ?? null;
        $integrity = hash('sha256', $binding.'|'.(string) $offset);
        if (
            ($payload['version'] ?? null) !== 1
            || ! is_int($offset)
            || $offset < 0
            || ! is_string($payload['binding'] ?? null)
            || ! hash_equals($binding, $payload['binding'])
            || ! is_string($payload['integrity'] ?? null)
            || ! hash_equals($integrity, $payload['integrity'])
        ) {
            return [0, ['cursor_binding_mismatch']];
        }

        return [$offset, []];
    }

    private function encodeBackfillCursor(
        string $artifactSha256,
        string $cohort,
        string $resumeKeySha256,
        int $hardMaxRows,
        int $offset,
    ): string {
        $binding = $this->backfillBinding(
            $artifactSha256,
            $cohort,
            $resumeKeySha256,
            $hardMaxRows,
        );
        $json = json_encode([
            'version' => 1,
            'binding' => $binding,
            'offset' => $offset,
            'integrity' => hash('sha256', $binding.'|'.$offset),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function backfillBinding(
        string $artifactSha256,
        string $cohort,
        string $resumeKeySha256,
        int $hardMaxRows,
    ): string {
        return hash('sha256', json_encode([
            self::BACKFILL_SCHEMA_VERSION,
            $artifactSha256,
            $cohort,
            $resumeKeySha256,
            $hardMaxRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchingIdempotencyKey(string $connection, array $row): ?string
    {
        $key = DB::connection($connection)
            ->table(self::TARGET_TABLE)
            ->whereRaw("COALESCE(TRIM(report_date), '') = ?", [$this->normalized($row['report_date'] ?? '')])
            ->whereRaw("COALESCE(TRIM(canonical_url_hash), '') = ?", [$this->normalized($row['canonical_url_hash'] ?? '')])
            ->whereRaw("COALESCE(TRIM(query_hash), '') = ?", [$this->normalized($row['query_hash'] ?? '')])
            ->whereRaw("COALESCE(TRIM(source_engine), '') = ?", [$this->normalized($row['source_engine'] ?? 'google')])
            ->whereRaw("COALESCE(TRIM(device), '') = ?", [$this->normalized($row['device'] ?? null)])
            ->whereRaw("COALESCE(TRIM(country), '') = ?", [$this->normalized($row['country'] ?? null)])
            ->whereRaw("COALESCE(TRIM(search_type), '') = ?", [$this->normalized($row['search_type'] ?? null)])
            ->value('idempotency_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function backfillReadbackReceipt(string $connection, array $keys): array
    {
        if ($keys === []) {
            return $this->emptyBackfillReadbackReceipt();
        }

        $keys = array_values(array_unique($keys));
        $found = DB::connection($connection)
            ->table(self::TARGET_TABLE)
            ->whereIn('idempotency_key', $keys)
            ->count();

        return [
            'status' => $found === count($keys) ? 'pass' : 'mismatch',
            'keys_checked' => count($keys),
            'rows_found' => $found,
            'rows_missing' => max(0, count($keys) - $found),
            'read_only' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBackfillReadbackReceipt(): array
    {
        return [
            'status' => 'not_run',
            'keys_checked' => 0,
            'rows_found' => 0,
            'rows_missing' => 0,
            'read_only' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function backfillNegativeGuarantees(): array
    {
        return [
            'database_write_outside_seo_gsc_daily' => false,
            'rows_beyond_hard_max' => false,
            'live_gsc_api_call' => false,
            'scheduler_activation' => false,
            'queue_worker_activation' => false,
            'opportunity_queue_enqueue' => false,
            'cms_write' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'request_indexing' => false,
            'sitemap_submission' => false,
            'new_credentials' => false,
            'raw_query_printed' => false,
            'raw_url_printed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function limitIssues(int $limit): array
    {
        return $limit >= self::MIN_LIMIT && $limit <= self::MAX_LIMIT ? [] : ['limit_must_be_between_1_and_10'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchingRowExists(string $connection, array $row): bool
    {
        return DB::connection($connection)
            ->table(self::TARGET_TABLE)
            ->whereRaw("COALESCE(TRIM(report_date), '') = ?", [$this->normalized($row['report_date'] ?? '')])
            ->whereRaw("COALESCE(TRIM(canonical_url_hash), '') = ?", [$this->normalized($row['canonical_url_hash'] ?? '')])
            ->whereRaw("COALESCE(TRIM(query_hash), '') = ?", [$this->normalized($row['query_hash'] ?? '')])
            ->whereRaw("COALESCE(TRIM(source_engine), '') = ?", [$this->normalized($row['source_engine'] ?? 'google')])
            ->whereRaw("COALESCE(TRIM(device), '') = ?", [$this->normalized($row['device'] ?? null)])
            ->whereRaw("COALESCE(TRIM(country), '') = ?", [$this->normalized($row['country'] ?? null)])
            ->whereRaw("COALESCE(TRIM(search_type), '') = ?", [$this->normalized($row['search_type'] ?? null)])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function insertPayload(
        array $row,
        string $artifactSha256,
        string $now,
        string $task = self::TASK,
    ): array {
        $metadata = (array) ($row['metadata_json'] ?? []);
        $metadata['source_artifact_sha256'] = $artifactSha256;
        $metadata['controlled_import_canary'] = $task === self::TASK;
        $metadata['bounded_backfill'] = $task === self::BACKFILL_TASK;
        $metadata['dry_run_import_preview'] = false;
        $metadata[$task === self::TASK ? 'canary_task' : 'backfill_task'] = $task;

        return [
            'idempotency_key' => $this->idempotencyKey($row),
            'report_date' => (string) $row['report_date'],
            'canonical_url_hash' => (string) $row['canonical_url_hash'],
            'canonical_url' => null,
            'query_hash' => (string) $row['query_hash'],
            'query_display_masked' => $row['query_display_masked'] ?? null,
            'locale' => $row['locale'] ?? null,
            'source_engine' => 'google',
            'device' => $row['device'] ?? null,
            'country' => $row['country'] ?? null,
            'search_type' => $row['search_type'] ?? null,
            'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
            'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
            'ctr_ppm' => $row['ctr_ppm'] ?? null,
            'average_position_milli' => $row['average_position_milli'] ?? null,
            'is_brand_query' => (bool) ($row['is_brand_query'] ?? false),
            'query_type' => (string) ($row['query_type'] ?? 'unknown'),
            'data_state' => (string) ($row['data_state'] ?? 'final'),
            'collected_at' => $now,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, bool>
     */
    /**
     * @return array<string, mixed>
     */
    private function writeBoundary(bool $writeAllowed): array
    {
        return [
            'seo_gsc_daily_write_allowed' => $writeAllowed,
            'target_table' => self::TARGET_TABLE,
            'max_rows_per_execution' => self::MAX_LIMIT,
            'idempotency_key_fields' => [
                'report_date',
                'canonical_url_hash',
                'query_hash',
                'source_engine',
                'device',
                'country',
                'search_type',
            ],
            'idempotency_unique_index' => 'seo_gsc_daily_idempotency_key_unique',
            'database_write_outside_seo_gsc_daily_allowed' => false,
            'canonical_url_policy' => 'null_until_separate_backend_url_truth_join_is_approved',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function idempotencyKey(array $row): string
    {
        return hash('sha256', json_encode([
            $this->normalized((string) ($row['report_date'] ?? '')),
            $this->normalized((string) ($row['canonical_url_hash'] ?? '')),
            $this->normalized((string) ($row['query_hash'] ?? '')),
            $this->normalized((string) ($row['source_engine'] ?? 'google')),
            $this->normalized($row['device'] ?? null),
            $this->normalized($row['country'] ?? null),
            $this->normalized($row['search_type'] ?? null),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function normalized(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write_outside_seo_gsc_daily' => false,
            'seo_gsc_daily_write_beyond_batch10_limit' => false,
            'migration_added' => false,
            'scheduler_activation' => false,
            'queue_worker_activation' => false,
            'opportunity_queue_enqueue' => false,
            'cms_write' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'search_provider_submission' => false,
            'gsc_url_inspection_request_indexing' => false,
            'sitemap_submission' => false,
            'live_gsc_api_call' => false,
            'credential_read_print_or_store' => false,
            'production_env_change' => false,
        ];
    }
}
