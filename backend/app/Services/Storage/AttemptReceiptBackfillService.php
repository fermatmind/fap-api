<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\AttemptReceipt;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttemptReceiptBackfillService
{
    /**
     * @var list<string>
     */
    private const LIFECYCLE_ACTIONS = [
        'storage_archive_report_artifacts',
        'storage_rehydrate_report_artifacts',
        'storage_shrink_archived_report_artifacts',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $meta
     */
    public function recordHistoricalReceipt(
        string $attemptId,
        string $receiptType,
        array $payload = [],
        array $meta = []
    ): ?AttemptReceipt {
        $attemptId = trim($attemptId);
        $receiptType = trim($receiptType);
        if ($attemptId === '' || $receiptType === '' || ! SchemaBaseline::hasTable('attempt_receipts')) {
            return null;
        }

        $payloadJson = $this->encodePayload($payload);
        $sourceSystem = $this->normalizeText($meta['source_system'] ?? 'backfill', 'backfill');
        $sourceRef = $this->normalizeNullableText($meta['source_ref'] ?? null);
        $actorType = $this->normalizeNullableText($meta['actor_type'] ?? null);
        $actorId = $this->normalizeNullableText($meta['actor_id'] ?? null);
        $occurredAt = $meta['occurred_at'] ?? null;
        $recordedAt = $meta['recorded_at'] ?? now();
        $idempotencyKey = $this->normalizeNullableText($meta['idempotency_key'] ?? null)
            ?? hash('sha256', implode('|', [
                $attemptId,
                $receiptType,
                $sourceSystem,
                $sourceRef ?? '',
                $payloadJson,
            ]));

        $work = function () use (
            $attemptId,
            $receiptType,
            $payload,
            $sourceSystem,
            $sourceRef,
            $actorType,
            $actorId,
            $occurredAt,
            $recordedAt,
            $idempotencyKey
        ): AttemptReceipt {
            if (SchemaBaseline::hasTable('attempts')) {
                DB::table('attempts')
                    ->where('id', $attemptId)
                    ->lockForUpdate()
                    ->first();
            }

            $existing = AttemptReceipt::query()
                ->where('attempt_id', $attemptId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof AttemptReceipt) {
                return $existing;
            }

            $nextSeq = ((int) (
                AttemptReceipt::query()
                    ->where('attempt_id', $attemptId)
                    ->lockForUpdate()
                    ->max('seq')
                ?? 0
            )) + 1;

            $receipt = AttemptReceipt::query()->create([
                'attempt_id' => $attemptId,
                'seq' => $nextSeq,
                'receipt_type' => $receiptType,
                'source_system' => $sourceSystem,
                'source_ref' => $sourceRef,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'payload_json' => $payload,
                'occurred_at' => $occurredAt ?? $recordedAt,
                'recorded_at' => $recordedAt,
            ]);

            return $receipt->fresh() ?? $receipt;
        };

        if (DB::transactionLevel() > 0) {
            return $work();
        }

        return DB::transaction($work);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function buildReplayPlan(array $filters = []): array
    {
        $rows = $this->matchingAuditRows($filters);
        $items = $this->receiptItemsFromRows($rows);

        return [
            'schema' => 'attempt_receipt_backfill_plan.v1',
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'source' => 'audit_logs.meta_json',
            'audit_rows_scanned' => count($rows),
            'receipt_candidates' => count($items),
            'unique_attempt_ids' => count(array_unique(array_map(
                static fn (array $item): string => (string) ($item['attempt_id'] ?? ''),
                $items
            ))),
            'receipt_types' => $this->countByKey($items, 'receipt_type'),
            'actions' => $this->countByKey($items, 'action'),
            'filters' => $this->normalizedFilters($filters),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function executeReplay(array $filters = []): array
    {
        $rows = $this->matchingAuditRows($filters);
        $items = $this->receiptItemsFromRows($rows);
        $inserted = 0;
        $reused = 0;
        $attemptIds = [];

        foreach ($items as $item) {
            $attemptId = trim((string) ($item['attempt_id'] ?? ''));
            $receiptType = trim((string) ($item['receipt_type'] ?? ''));
            if ($attemptId === '' || $receiptType === '') {
                continue;
            }

            $attemptIds[$attemptId] = true;
            $hadReceipt = AttemptReceipt::query()
                ->where('attempt_id', $attemptId)
                ->where('idempotency_key', (string) data_get($item, 'meta.idempotency_key', ''))
                ->exists();
            $receipt = $this->recordHistoricalReceipt(
                $attemptId,
                $receiptType,
                (array) ($item['payload'] ?? []),
                (array) ($item['meta'] ?? [])
            );

            if ($receipt === null) {
                continue;
            }

            if ($hadReceipt) {
                $reused++;

                continue;
            }

            $inserted++;
        }

        return [
            'schema' => 'attempt_receipt_backfill_run.v1',
            'mode' => 'execute',
            'status' => 'executed',
            'generated_at' => now()->toIso8601String(),
            'source' => 'audit_logs.meta_json',
            'audit_rows_scanned' => count($rows),
            'receipt_candidates' => count($items),
            'attempt_receipts_inserted' => $inserted,
            'attempt_receipts_reused' => $reused,
            'unique_attempt_ids' => count($attemptIds),
            'receipt_types' => $this->countByKey($items, 'receipt_type'),
            'actions' => $this->countByKey($items, 'action'),
            'filters' => $this->normalizedFilters($filters),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return list<object>
     */
    private function matchingAuditRows(array $filters): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $attemptId = $this->normalizeNullableText($filters['attempt_id'] ?? null);
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;

        $query = DB::table('audit_logs')
            ->whereIn('action', self::LIFECYCLE_ACTIONS)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit(max(1, $limit * 25));
        }

        $rows = $query->get()->all();
        if ($attemptId === null) {
            return $limit === null ? $rows : array_slice($rows, 0, $limit);
        }

        $rows = array_values(array_filter($rows, static function (object $row) use ($attemptId): bool {
            if ((string) ($row->target_id ?? '') === $attemptId) {
                return true;
            }

            $meta = json_decode((string) ($row->meta_json ?? '{}'), true);
            if (! is_array($meta)) {
                return false;
            }

            if ((string) data_get($meta, 'attempt_id', '') === $attemptId) {
                return true;
            }

            foreach ((array) data_get($meta, 'results', []) as $result) {
                if (is_array($result) && (string) ($result['attempt_id'] ?? '') === $attemptId) {
                    return true;
                }
            }

            return false;
        }));

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string,mixed>>
     */
    private function receiptItemsFromRows(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $meta = $this->decodeJsonObject($row->meta_json ?? null);
            $action = (string) ($row->action ?? '');
            $receiptType = $this->receiptTypeForAction($action);
            if ($receiptType === null) {
                continue;
            }

            $results = is_array($meta['results'] ?? null) ? array_values($meta['results']) : [];
            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $attemptId = $this->normalizeNullableText($result['attempt_id'] ?? null);
                if ($attemptId === null) {
                    continue;
                }

                $sourceRef = sprintf(
                    'audit_logs#%s|%s|%s|%s',
                    (string) ($row->id ?? ''),
                    $action,
                    $attemptId,
                    $this->normalizeNullableText($result['source_path'] ?? null) ?? $this->normalizeNullableText($result['target_object_key'] ?? null) ?? 'unknown'
                );

                $items[] = [
                    'action' => $action,
                    'attempt_id' => $attemptId,
                    'receipt_type' => $receiptType,
                    'payload' => [
                        'job_type' => $action,
                        'status' => $result['status'] ?? null,
                        'kind' => $result['kind'] ?? null,
                        'source_path' => $result['source_path'] ?? null,
                        'target_object_key' => $result['target_object_key'] ?? null,
                        'target_disk' => $result['target_disk'] ?? null,
                        'summary' => $meta['summary'] ?? [],
                        'run_path' => $meta['run_path'] ?? null,
                    ],
                    'meta' => [
                        'source_system' => 'audit_logs.meta_json',
                        'source_ref' => $sourceRef,
                        'actor_type' => 'system',
                        'actor_id' => $action,
                        'idempotency_key' => hash('sha256', implode('|', [
                            $attemptId,
                            $receiptType,
                            $action,
                            $sourceRef,
                            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ])),
                        'occurred_at' => $row->created_at ?? now(),
                        'recorded_at' => $row->created_at ?? now(),
                    ],
                ];
            }
        }

        return $items;
    }

    private function receiptTypeForAction(string $action): ?string
    {
        return match ($action) {
            'storage_archive_report_artifacts' => 'artifact_archived',
            'storage_rehydrate_report_artifacts' => 'artifact_rehydrated',
            'storage_shrink_archived_report_artifacts' => 'artifact_shrunk',
            default => null,
        };
    }

    /**
     * @param  array<int, array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function countByKey(array $items, string $key): array
    {
        $counts = [];
        foreach ($items as $item) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value === '') {
                $value = 'unknown';
            }

            $counts[$value] = (int) ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function normalizedFilters(array $filters): array
    {
        return [
            'attempt_id' => $this->normalizeNullableText($filters['attempt_id'] ?? null),
            'limit' => isset($filters['limit']) ? max(1, (int) $filters['limit']) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new \RuntimeException('failed to encode attempt receipt payload.');
        }

        return $json;
    }

    private function normalizeText(mixed $value, string $fallback = ''): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
