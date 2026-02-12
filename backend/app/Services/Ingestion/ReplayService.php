<?php

namespace App\Services\Ingestion;

use App\Support\Idempotency\IdempotencyKey;
use App\Support\Idempotency\IdempotencyStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReplayService
{
    public function replay(string $provider, string $batchId): array
    {
        if (!Schema::hasTable('ingest_batches')) {
            return [
                'ok' => false,
                'error' => 'MISSING_TABLE',
                'message' => 'ingest_batches table not found',
            ];
        }

        $batch = DB::table('ingest_batches')->where('id', $batchId)->first();
        if (!$batch) {
            return [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'ingest batch not found',
            ];
        }

        $inserted = 0;
        $skipped = 0;
        $runId = (string) Str::uuid();
        $store = new IdempotencyStore();
        $tables = [
            'sleep_samples' => Schema::hasTable('sleep_samples'),
            'screen_time_samples' => Schema::hasTable('screen_time_samples'),
            'health_samples' => Schema::hasTable('health_samples'),
        ];

        $this->streamSamplesFromTable('sleep_samples', $batchId, 'sleep', $provider, $runId, $store, $tables, $inserted, $skipped);
        $this->streamSamplesFromTable('screen_time_samples', $batchId, 'screen_time', $provider, $runId, $store, $tables, $inserted, $skipped);
        $this->streamSamplesFromTable('health_samples', $batchId, 'health', $provider, $runId, $store, $tables, $inserted, $skipped);

        DB::table('ingest_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'replayed',
            ]);

        return [
            'ok' => true,
            'batch_id' => $batchId,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ];
    }

    private function streamSamplesFromTable(
        string $sourceTable,
        string $batchId,
        string $defaultDomain,
        string $provider,
        string $runId,
        IdempotencyStore $store,
        array $tables,
        int &$inserted,
        int &$skipped
    ): void
    {
        if (!($tables[$sourceTable] ?? false)) {
            return;
        }

        $maxId = (int) DB::table($sourceTable)
            ->where('ingest_batch_id', $batchId)
            ->max('id');

        if ($maxId <= 0) {
            return;
        }

        $sourceInserted = 0;
        $sourceSkipped = 0;
        $sourceChunks = 0;

        DB::table($sourceTable)
            ->where('ingest_batch_id', $batchId)
            ->where('id', '<=', $maxId)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (
                $batchId,
                $defaultDomain,
                &$inserted,
                $provider,
                $runId,
                &$skipped,
                $store,
                $tables,
                &$sourceInserted,
                &$sourceSkipped,
                &$sourceChunks
            ): void {
                $sourceChunks++;
                $sleepInserts = [];
                $screenTimeInserts = [];
                $healthInserts = [];
                $idempotencyRows = [];
                $samplePayloadsByExternalId = [];
                $externalIdsThisChunk = [];
                $now = now();

                foreach ($rows as $row) {
                    $sample = $this->normalizeSample($row, $defaultDomain);
                    $domain = (string) ($sample['domain'] ?? '');
                    $recordedAt = (string) ($sample['recorded_at'] ?? '');
                    $externalId = (string) ($sample['external_id'] ?? '');
                    $value = $sample['value'] ?? [];

                    $idKey = IdempotencyKey::build($provider, $externalId !== '' ? $externalId : $recordedAt, $recordedAt, $value);
                    $resolvedExternalId = (string) ($idKey['external_id'] ?? '');
                    $idempotencyRows[] = [
                        'provider' => $idKey['provider'],
                        'external_id' => $resolvedExternalId,
                        'recorded_at' => $idKey['recorded_at'],
                        'hash' => $idKey['hash'],
                        'run_id' => $runId,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'ingest_batch_id' => $batchId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $externalIdsThisChunk[] = $resolvedExternalId;

                    $payloadHash = IdempotencyKey::hashPayload($value);
                    $confidence = (float) ($sample['confidence'] ?? 1.0);
                    $userId = $sample['user_id'] ?? null;
                    $source = (string) ($sample['source'] ?? $provider);

                    $payload = [
                        'user_id' => $userId,
                        'source' => $source !== '' ? $source : 'ingestion',
                        'recorded_at' => $recordedAt,
                        'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'confidence' => $confidence,
                        'raw_payload_hash' => $payloadHash,
                        'ingest_batch_id' => $batchId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $samplePayloadsByExternalId[$resolvedExternalId] = [
                        'domain' => $domain,
                        'payload' => $payload,
                    ];
                }

                if ($idempotencyRows === []) {
                    return;
                }

                $store->recordFastBatch($idempotencyRows);
                $insertedExternalIds = $store->pluckInsertedExternalIds($provider, $runId, $externalIdsThisChunk);
                $insertedLookup = array_fill_keys($insertedExternalIds, true);

                foreach ($samplePayloadsByExternalId as $sampleExternalId => $samplePayload) {
                    if (!isset($insertedLookup[$sampleExternalId])) {
                        $skipped++;
                        $sourceSkipped++;
                        continue;
                    }

                    $domain = (string) ($samplePayload['domain'] ?? '');
                    $payload = $samplePayload['payload'] ?? [];

                    if ($domain === 'sleep' && ($tables['sleep_samples'] ?? false)) {
                        $sleepInserts[] = $payload;
                        continue;
                    }

                    if ($domain === 'screen_time' && ($tables['screen_time_samples'] ?? false)) {
                        $screenTimeInserts[] = $payload;
                        continue;
                    }

                    if ($tables['health_samples'] ?? false) {
                        $healthPayload = $payload;
                        $healthPayload['domain'] = $domain !== '' ? $domain : 'unknown';
                        $healthInserts[] = $healthPayload;
                        continue;
                    }

                    $skipped++;
                    $sourceSkipped++;
                }

                $chunkInserted = 0;
                $chunkInserted += $this->flushInserts('sleep_samples', $sleepInserts);
                $chunkInserted += $this->flushInserts('screen_time_samples', $screenTimeInserts);
                $chunkInserted += $this->flushInserts('health_samples', $healthInserts);

                $inserted += $chunkInserted;
                $sourceInserted += $chunkInserted;
            }, 'id');

        Log::info('replay source processed', [
            'source_table' => $sourceTable,
            'batch_id' => $batchId,
            'inserted' => $sourceInserted,
            'skipped' => $sourceSkipped,
            'chunks' => $sourceChunks,
        ]);
    }

    private function normalizeSample(object $row, string $domain): array
    {
        $value = [];
        if (isset($row->value_json)) {
            $decoded = json_decode((string) $row->value_json, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        return [
            'domain' => $domain === 'health' ? ((string) ($row->domain ?? $domain)) : $domain,
            'recorded_at' => (string) ($row->recorded_at ?? ''),
            'external_id' => (string) ($row->id ?? ''),
            'value' => $value,
            'confidence' => (float) ($row->confidence ?? 1.0),
            'user_id' => $row->user_id ?? null,
            'source' => (string) ($row->source ?? ''),
        ];
    }

    private function flushInserts(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table($table)->insert($rows);

        return count($rows);
    }
}
