<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Facades\DB;

final class GscReadModelCanaryReadbackAuditor
{
    public const SCHEMA_VERSION = 'gsc-readmodel-canary-readback.v1';

    public const TASK = 'GSC-READMODEL-CANARY-READBACK-01';

    public const TARGET_TABLE = 'seo_gsc_daily';

    public function __construct(private readonly GscReadModelArtifactDryRunImporter $dryRunImporter) {}

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function audit(array $artifact, string $artifactSha256, string $confirmedArtifactSha256, int $limit = 250): array
    {
        $issues = [];
        if (! hash_equals($artifactSha256, $confirmedArtifactSha256)) {
            $issues[] = 'artifact_sha256_mismatch';
        }

        $preview = $this->dryRunImporter->preview($artifact, $limit);
        if (($preview['ok'] ?? false) !== true) {
            $issues[] = 'dry_run_importer_validation_failed';
        }

        $rows = $issues === []
            ? array_values(array_filter((array) ($preview['preview_rows'] ?? []), 'is_array'))
            : [];
        $keys = $this->idempotencyKeys($rows);
        $readback = $keys === [] ? [] : $this->readbackExistingKeys($keys);

        $distinctKeys = count($keys);
        $foundKeys = count($readback);
        $rowsMissing = max(0, $distinctKeys - $foundKeys);
        $ok = $issues === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => $ok ? 'success' : 'blocked',
            'ok' => $ok,
            'read_only' => true,
            'dry_run' => true,
            'would_write' => false,
            'target_connection' => (string) config('seo_intel.connection', 'seo_intel'),
            'target_table' => self::TARGET_TABLE,
            'artifact_sha256' => $artifactSha256,
            'artifact_sha256_confirmed' => $ok && hash_equals($artifactSha256, $confirmedArtifactSha256),
            'rows_previewed' => count($rows),
            'idempotency_key_count' => $distinctKeys,
            'rows_found' => array_sum($readback),
            'distinct_keys' => $foundKeys,
            'rows_missing' => $rowsMissing,
            'would_duplicate' => $foundKeys > 0,
            'all_rows_already_present' => $distinctKeys > 0 && $rowsMissing === 0,
            'data_origin' => $preview['data_origin'] ?? null,
            'data_quality_gate' => $preview['data_quality_gate'] ?? null,
            'date_window' => $preview['date_window'] ?? null,
            'dry_run_importer_errors' => (array) ($preview['errors'] ?? []),
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => [
                'database_write' => false,
                'seo_gsc_daily_write' => false,
                'scheduler_activation' => false,
                'queue_worker_activation' => false,
                'opportunity_queue_enqueue' => false,
                'cms_write' => false,
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'search_provider_submission' => false,
                'indexing_request' => false,
                'sitemap_submission' => false,
                'live_gsc_api_call' => false,
                'raw_query_printed' => false,
                'raw_url_printed' => false,
                'credential_read_print_or_store' => false,
                'production_env_change' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function idempotencyKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = hash('sha256', implode('|', [
                $this->normalized($row['report_date'] ?? ''),
                $this->normalized($row['canonical_url_hash'] ?? ''),
                $this->normalized($row['query_hash'] ?? ''),
                $this->normalized($row['source_engine'] ?? 'google'),
                $this->normalized($row['device'] ?? ''),
                $this->normalized($row['country'] ?? ''),
                $this->normalized($row['search_type'] ?? ''),
            ]));
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function readbackExistingKeys(array $keys): array
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table(self::TARGET_TABLE)
            ->selectRaw('idempotency_key, COUNT(*) as row_count')
            ->whereIn('idempotency_key', $keys)
            ->groupBy('idempotency_key')
            ->pluck('row_count', 'idempotency_key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function normalized(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
