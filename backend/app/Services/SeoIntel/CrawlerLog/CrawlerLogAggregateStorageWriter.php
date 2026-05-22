<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

use Illuminate\Support\Facades\DB;

final class CrawlerLogAggregateStorageWriter
{
    public const TARGET_TABLE = 'seo_crawler_log_daily_aggregates';

    /**
     * @param  list<array<string, mixed>>  $aggregateRows
     * @return array<string, mixed>
     */
    public function write(array $aggregateRows, bool $dryRun = true, bool $noWrite = true): array
    {
        $writeGateEnabled = (bool) config('seo_intel.crawler_log_aggregate_storage.write_enabled', false);
        $writeRequested = ! $dryRun && ! $noWrite;
        $sanitizedRows = array_map(fn (array $row): array => $this->storageRow($row), $aggregateRows);

        $payload = [
            'runtime' => 'crawler_log_aggregate_storage',
            'target_table' => self::TARGET_TABLE,
            'dry_run' => $dryRun,
            'no_write' => $noWrite,
            'write_gate_env' => (string) config('seo_intel.crawler_log_aggregate_storage.write_gate_env', 'SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED'),
            'write_gate_enabled' => $writeGateEnabled,
            'writes_attempted' => false,
            'writes_committed' => false,
            'written_rows' => 0,
            'planned_rows' => count($sanitizedRows),
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'production_log_read_attempted' => false,
            'scheduler_enabled' => false,
            'collector_write_attempted' => false,
            'raw_persistence' => false,
            'issues' => [],
            'safety_flags' => [
                'no_raw_persistence' => true,
                'no_production_log_read' => true,
                'no_scheduler' => true,
                'no_external_search_api_call' => true,
                'no_search_submission' => true,
                'no_url_truth_mutation' => true,
                'no_issue_queue_auto_write' => true,
                'no_search_channel_queue_creation' => true,
            ],
        ];

        if (! $writeRequested) {
            return $payload;
        }

        $payload['writes_attempted'] = true;

        if (! $writeGateEnabled) {
            $payload['status'] = 'blocked';
            $payload['issues'][] = 'write_gate_disabled';

            return $payload;
        }

        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();
        $writtenRows = 0;

        foreach ($sanitizedRows as $row) {
            $connection->table(self::TARGET_TABLE)->updateOrInsert(
                ['idempotency_key' => $row['idempotency_key']],
                $row + [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $writtenRows++;
        }

        $payload['status'] = 'success';
        $payload['writes_committed'] = $writtenRows > 0;
        $payload['written_rows'] = $writtenRows;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function storageRow(array $row): array
    {
        $canonicalPath = $this->nullableString($row['canonical_path'] ?? null);
        $pathHash = $this->nullableString($row['path_hash'] ?? null);

        $safeRow = [
            'log_date' => (string) ($row['log_date'] ?? now()->toDateString()),
            'host' => (string) ($row['host'] ?? 'unknown_host'),
            'surface_family' => (string) ($row['surface_family'] ?? 'unknown'),
            'bot_family' => (string) ($row['bot_family'] ?? 'unknown_bot'),
            'bot_variant' => (string) ($row['bot_variant'] ?? 'unknown'),
            'bot_verification_state' => (string) ($row['bot_verification_state'] ?? 'ua_claim_only'),
            'route_family' => (string) ($row['route_family'] ?? 'unknown_public_path'),
            'page_entity_type' => $this->nullableString($row['page_entity_type'] ?? null),
            'canonical_path' => $canonicalPath,
            'path_hash' => $pathHash,
            'http_status' => $this->nullableInt($row['http_status'] ?? null),
            'method_bucket' => (string) ($row['method_bucket'] ?? 'OTHER'),
            'query_present' => (bool) ($row['query_present'] ?? false),
            'query_risk_state' => (string) ($row['query_risk_state'] ?? 'none'),
            'private_path_blocked' => (bool) ($row['private_path_blocked'] ?? false),
            'hit_count' => max(0, (int) ($row['hit_count'] ?? 0)),
            'first_seen_at' => $this->nullableString($row['first_seen_at'] ?? null),
            'last_seen_at' => $this->nullableString($row['last_seen_at'] ?? null),
            'source_log_family' => (string) ($row['source_log_family'] ?? 'nginx_openresty_access_log'),
            'privacy_transform_version' => (string) ($row['privacy_transform_version'] ?? CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION),
        ];

        $safeRow['idempotency_key'] = $this->idempotencyKey($safeRow);

        return $safeRow;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function idempotencyKey(array $row): string
    {
        return hash('sha256', json_encode([
            $row['log_date'],
            $row['host'],
            $row['surface_family'],
            $row['bot_family'],
            $row['bot_variant'],
            $row['bot_verification_state'],
            $row['route_family'],
            $row['page_entity_type'],
            $row['canonical_path'],
            $row['path_hash'],
            $row['http_status'],
            $row['method_bucket'],
            $row['query_present'],
            $row['query_risk_state'],
            $row['private_path_blocked'],
            $row['source_log_family'],
            $row['privacy_transform_version'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
