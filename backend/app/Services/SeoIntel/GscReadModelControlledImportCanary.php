<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class GscReadModelControlledImportCanary
{
    public const SCHEMA_VERSION = 'gsc-readmodel-controlled-import-canary.v1';

    public const TASK = 'SEO-GSC-READMODEL-CONTROLLED-IMPORT-CANARY-01';

    public const TARGET_TABLE = 'seo_gsc_daily';

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
            ->where('idempotency_key', $this->idempotencyKey($row))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function insertPayload(array $row, string $artifactSha256, string $now): array
    {
        $metadata = (array) ($row['metadata_json'] ?? []);
        $metadata['source_artifact_sha256'] = $artifactSha256;
        $metadata['controlled_import_canary'] = true;
        $metadata['dry_run_import_preview'] = false;
        $metadata['canary_task'] = self::TASK;

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
        return hash('sha256', implode('|', [
            $this->normalized((string) ($row['report_date'] ?? '')),
            $this->normalized((string) ($row['canonical_url_hash'] ?? '')),
            $this->normalized((string) ($row['query_hash'] ?? '')),
            $this->normalized((string) ($row['source_engine'] ?? 'google')),
            $this->normalized($row['device'] ?? null),
            $this->normalized($row['country'] ?? null),
            $this->normalized($row['search_type'] ?? null),
        ]));
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
