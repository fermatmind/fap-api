<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class ScheduledRuntimeProbeReceiptService
{
    public const SCHEMA_VERSION = 'seo-platform-07-scheduled-receipt.v2';

    public const SLOT_MINUTES = 10;

    public function __construct(private readonly ?string $connectionName = null) {}

    /** @return array<string,mixed> */
    public function record(string $triggerMode = 'manual', ?string $now = null, ?array $calibration = null): array
    {
        if (! in_array($triggerMode, ['manual', 'scheduled'], true)) {
            throw new InvalidArgumentException('Runtime probe trigger mode is invalid.');
        }

        $observedAt = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($now)->utc();
        $scheduledFor = $observedAt->startOfMinute()->subMinutes($observedAt->minute % self::SLOT_MINUTES);
        $slotKey = $triggerMode.'|'.$scheduledFor->format('Y-m-d\TH:i:00\Z');
        $crawler = $this->crawlerSourceReceipt($observedAt);
        $calibration ??= $this->missingCalibration();
        $status = ($crawler['complete'] ?? false) === true && ($calibration['state'] ?? null) === 'success'
            ? 'success'
            : UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD;
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'slot_key' => $slotKey,
            'trigger_mode' => $triggerMode,
            'status' => $status,
            'scheduled_for' => $scheduledFor->toAtomString(),
            'completed_at' => $observedAt->toAtomString(),
            'crawler_source_receipt' => $crawler,
            'production_calibration' => $calibration,
            'freshness' => [
                'maximum_receipt_age_minutes' => self::SLOT_MINUTES * 2,
                'maximum_crawler_source_age_minutes' => 2_880,
            ],
            'boundaries' => [
                'scheduled_receipt_is_observation_only' => true,
                'manual_receipt_counts_as_natural_slot' => false,
                'raw_url_emitted' => false,
                'query_emitted' => false,
                'user_agent_emitted' => false,
                'response_body_emitted' => false,
                'raw_topology_emitted' => false,
                'search_submission_allowed' => false,
                'write_authorization_granted' => false,
            ],
        ];
        $receiptHash = self::contentHash($receipt);
        $receipt['receipt_hash'] = $receiptHash;

        $this->connection()->table('seo_runtime_probe_receipts')->insertOrIgnore([
            'slot_key' => $slotKey,
            'trigger_mode' => $triggerMode,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'completed_at' => $observedAt,
            'receipt_hash' => $receiptHash,
            'crawler_source_receipt_json' => json_encode($crawler, JSON_THROW_ON_ERROR),
            'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);

        $stored = $this->connection()->table('seo_runtime_probe_receipts')->where('slot_key', $slotKey)->first();

        return $stored === null ? $receipt : $this->decode((string) $stored->receipt_json);
    }

    /** @return array<string,mixed> */
    public function readWindow(?string $now = null): array
    {
        $observedAt = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($now)->utc();
        $schema = Schema::connection($this->connection()->getName());
        if (! $schema->hasTable('seo_runtime_probe_receipts')) {
            return [
                'state' => UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
                'slot_count' => null,
                'consecutive' => false,
                'fresh' => false,
                'successful' => false,
                'receipts' => [],
                'boundaries' => [
                    'manual_receipts_excluded' => true,
                    'fixtures_count_as_production_evidence' => false,
                    'raw_sensitive_evidence_emitted' => false,
                ],
            ];
        }
        $rows = $this->connection()->table('seo_runtime_probe_receipts')
            ->where('trigger_mode', 'scheduled')
            ->orderByDesc('scheduled_for')
            ->limit(3)
            ->get();
        $receipts = $rows->map(fn (object $row): array => $this->decode((string) $row->receipt_json))->all();
        $consecutive = count($receipts) === 3;
        for ($index = 1; $index < count($receipts); $index++) {
            $newer = CarbonImmutable::parse((string) $receipts[$index - 1]['scheduled_for']);
            $older = CarbonImmutable::parse((string) $receipts[$index]['scheduled_for']);
            $consecutive = $consecutive && (int) $older->diffInMinutes($newer) === self::SLOT_MINUTES;
        }
        $latestAt = $receipts === [] ? null : CarbonImmutable::parse((string) $receipts[0]['completed_at']);
        $fresh = $latestAt !== null && $latestAt->diffInMinutes($observedAt, false) <= self::SLOT_MINUTES * 2;
        $successful = count($receipts) === 3
            && collect($receipts)->every(static fn (array $receipt): bool => ($receipt['status'] ?? null) === 'success');
        $complete = $consecutive && $fresh && $successful;

        return [
            'state' => $complete ? 'complete' : UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
            'slot_count' => count($receipts),
            'consecutive' => $consecutive,
            'fresh' => $fresh,
            'successful' => $successful,
            'receipts' => $receipts,
            'boundaries' => [
                'manual_receipts_excluded' => true,
                'fixtures_count_as_production_evidence' => false,
                'raw_sensitive_evidence_emitted' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function crawlerSourceReceipt(CarbonImmutable $now): array
    {
        $connection = $this->connection();
        $schema = Schema::connection($connection->getName());
        if (! $schema->hasTable('seo_crawler_log_daily_aggregates')) {
            return $this->missingCrawler('table_missing');
        }

        $rowCount = $connection->table('seo_crawler_log_daily_aggregates')->count();
        $hitCount = (int) $connection->table('seo_crawler_log_daily_aggregates')->sum('hit_count');
        $observationColumn = $schema->hasColumn('seo_crawler_log_daily_aggregates', 'last_seen_at')
            ? 'last_seen_at'
            : 'updated_at';
        $latest = $connection->table('seo_crawler_log_daily_aggregates')
            ->where($observationColumn, '<=', $now->format('Y-m-d H:i:s'))
            ->max($observationColumn);
        $latestAt = $latest === null ? null : CarbonImmutable::parse((string) $latest)->utc();
        $ageMinutes = $latestAt?->diffInMinutes($now, false);
        $complete = $rowCount > 0 && $hitCount > 0 && $ageMinutes !== null && $ageMinutes >= 0 && $ageMinutes <= 2_880;

        return [
            'schema_version' => 'seo-platform-07-crawler-source-receipt.v1',
            'complete' => $complete,
            'row_count' => $rowCount,
            'hit_count' => $hitCount,
            'latest_observation_at' => $latestAt?->toAtomString(),
            'observation_time_basis' => $observationColumn,
            'age_minutes' => $ageMinutes,
            'source_identity_hash' => hash('sha256', implode('|', [$rowCount, $hitCount, (string) $latest])),
            'raw_url_emitted' => false,
            'query_emitted' => false,
            'user_agent_emitted' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function missingCrawler(string $reason): array
    {
        return [
            'schema_version' => 'seo-platform-07-crawler-source-receipt.v1',
            'complete' => false,
            'unavailable_reason' => $reason,
            'row_count' => null,
            'hit_count' => null,
            'latest_observation_at' => null,
            'age_minutes' => null,
            'source_identity_hash' => null,
            'raw_url_emitted' => false,
            'query_emitted' => false,
            'user_agent_emitted' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function missingCalibration(): array
    {
        return [
            'schema_version' => ProductionCalibrationProbeService::SCHEMA_VERSION,
            'state' => UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
            'expected_cell_count' => 12,
            'observed_cell_count' => 0,
            'cells' => [],
            'private_negative_set' => [
                'checked' => false,
                'accepted' => false,
                'exposure_count' => null,
            ],
            'deploy_revision' => null,
            'unavailable_reason' => 'calibration_not_supplied',
        ];
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel'));
    }

    /** @param array<string,mixed> $receipt */
    public static function contentHash(array $receipt): string
    {
        unset($receipt['receipt_hash']);

        return hash('sha256', json_encode(self::canonicalize($receipt), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value)) {
            $rounded = round($value, 6);

            return floor($rounded) === $rounded ? (int) $rounded : $rounded;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonicalize(...), $value);
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
