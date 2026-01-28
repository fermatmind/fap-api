<?php

namespace App\Services\Ingestion;

use App\Support\Idempotency\IdempotencyKey;
use App\Support\Idempotency\IdempotencyStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IngestionService
{
    public function ingestSamples(string $provider, ?string $userId, array $batchMeta, array $samples): array
    {
        if (!Schema::hasTable('ingest_batches')) {
            return [
                'ok' => false,
                'error' => 'MISSING_TABLE',
                'message' => 'ingest_batches table not found',
            ];
        }

        $batchId = (string) Str::uuid();
        $rangeStart = $batchMeta['range_start'] ?? null;
        $rangeEnd = $batchMeta['range_end'] ?? null;
        if (is_string($rangeStart) && trim($rangeStart) !== '') {
            $rangeStart = IdempotencyKey::normalizeRecordedAt($rangeStart);
        } elseif ($rangeStart instanceof \DateTimeInterface) {
            $rangeStart = IdempotencyKey::normalizeRecordedAt($rangeStart);
        } else {
            $rangeStart = null;
        }
        if (is_string($rangeEnd) && trim($rangeEnd) !== '') {
            $rangeEnd = IdempotencyKey::normalizeRecordedAt($rangeEnd);
        } elseif ($rangeEnd instanceof \DateTimeInterface) {
            $rangeEnd = IdempotencyKey::normalizeRecordedAt($rangeEnd);
        } else {
            $rangeEnd = null;
        }
        $rawPayloadHash = $batchMeta['raw_payload_hash'] ?? null;

        $now = now();
        DB::table('ingest_batches')->insert([
            'id' => $batchId,
            'provider' => $provider,
            'user_id' => $userId !== null ? (int) $userId : null,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'raw_payload_hash' => $rawPayloadHash,
            'status' => 'received',
            'created_at' => $now,
        ]);

        $inserted = 0;
        $skipped = 0;
        $store = new IdempotencyStore();

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                $skipped++;
                continue;
            }

            $domain = (string) ($sample['domain'] ?? '');
            $recordedAt = (string) ($sample['recorded_at'] ?? '');
            $externalId = (string) ($sample['external_id'] ?? '');
            $source = (string) ($sample['source'] ?? $provider);

            if ($recordedAt === '') {
                $skipped++;
                continue;
            }

            $value = $sample['value'] ?? $sample;
            $recordedAtNormalized = IdempotencyKey::normalizeRecordedAt($recordedAt);
            $idKey = IdempotencyKey::build($provider, $externalId !== '' ? $externalId : $recordedAtNormalized, $recordedAtNormalized, $value);
            $idRecord = $store->record([
                'provider' => $idKey['provider'],
                'external_id' => $idKey['external_id'],
                'recorded_at' => $idKey['recorded_at'],
                'hash' => $idKey['hash'],
                'ingest_batch_id' => $batchId,
            ]);

            if (($idRecord['existing'] ?? false) === true) {
                $skipped++;
                continue;
            }

            $payloadHash = IdempotencyKey::hashPayload($value);
            $confidence = (float) ($sample['confidence'] ?? 1.0);

            if ($domain === 'sleep' && Schema::hasTable('sleep_samples')) {
                DB::table('sleep_samples')->insert([
                    'user_id' => $userId !== null ? (int) $userId : null,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'recorded_at' => $recordedAtNormalized,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
                continue;
            }

            if ($domain === 'screen_time' && Schema::hasTable('screen_time_samples')) {
                DB::table('screen_time_samples')->insert([
                    'user_id' => $userId !== null ? (int) $userId : null,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'recorded_at' => $recordedAtNormalized,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
                continue;
            }

            if (Schema::hasTable('health_samples')) {
                DB::table('health_samples')->insert([
                    'user_id' => $userId !== null ? (int) $userId : null,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'domain' => $domain !== '' ? $domain : 'unknown',
                    'recorded_at' => $recordedAtNormalized,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
                continue;
            }

            $skipped++;
        }

        DB::table('ingest_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'processed',
            ]);

        return [
            'ok' => true,
            'batch_id' => $batchId,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ];
    }
}
