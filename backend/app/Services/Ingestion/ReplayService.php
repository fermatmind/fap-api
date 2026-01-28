<?php

namespace App\Services\Ingestion;

use App\Support\Idempotency\IdempotencyKey;
use App\Support\Idempotency\IdempotencyStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $samples = [];
        $samples = array_merge($samples, $this->loadSamples('sleep_samples', $batchId, 'sleep'));
        $samples = array_merge($samples, $this->loadSamples('screen_time_samples', $batchId, 'screen_time'));
        $samples = array_merge($samples, $this->loadSamples('health_samples', $batchId, 'health'));

        $inserted = 0;
        $skipped = 0;
        $store = new IdempotencyStore();

        foreach ($samples as $sample) {
            $domain = (string) ($sample['domain'] ?? '');
            $recordedAt = (string) ($sample['recorded_at'] ?? '');
            $externalId = (string) ($sample['external_id'] ?? '');
            $value = $sample['value'] ?? [];

            $idKey = IdempotencyKey::build($provider, $externalId !== '' ? $externalId : $recordedAt, $recordedAt, $value);
            $existing = $store->findByPayload($idKey['provider'], $idKey['recorded_at'], $idKey['hash']);
            if ($existing) {
                $store->touch($idKey['provider'], $idKey['external_id'], $idKey['recorded_at'], $idKey['hash']);
                $skipped++;
                continue;
            }

            $record = $store->record([
                'provider' => $idKey['provider'],
                'external_id' => $idKey['external_id'],
                'recorded_at' => $idKey['recorded_at'],
                'hash' => $idKey['hash'],
                'ingest_batch_id' => $batchId,
            ]);
            if (($record['inserted'] ?? false) === false) {
                $skipped++;
                continue;
            }

            $payloadHash = IdempotencyKey::hashPayload($value);
            $confidence = (float) ($sample['confidence'] ?? 1.0);
            $userId = $sample['user_id'] ?? null;
            $source = (string) ($sample['source'] ?? $provider);

            if ($domain === 'sleep' && Schema::hasTable('sleep_samples')) {
                DB::table('sleep_samples')->insert([
                    'user_id' => $userId,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'recorded_at' => $recordedAt,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
                continue;
            }

            if ($domain === 'screen_time' && Schema::hasTable('screen_time_samples')) {
                DB::table('screen_time_samples')->insert([
                    'user_id' => $userId,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'recorded_at' => $recordedAt,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
                continue;
            }

            if (Schema::hasTable('health_samples')) {
                DB::table('health_samples')->insert([
                    'user_id' => $userId,
                    'source' => $source !== '' ? $source : 'ingestion',
                    'domain' => $domain !== '' ? $domain : 'unknown',
                    'recorded_at' => $recordedAt,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $confidence,
                    'raw_payload_hash' => $payloadHash,
                    'ingest_batch_id' => $batchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
                continue;
            }

            $skipped++;
        }

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

    private function loadSamples(string $table, string $batchId, string $domain): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $rows = DB::table($table)->where('ingest_batch_id', $batchId)->get();
        $out = [];
        foreach ($rows as $row) {
            $value = [];
            if (isset($row->value_json)) {
                $decoded = json_decode((string) $row->value_json, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            $out[] = [
                'domain' => $domain === 'health' ? ((string) ($row->domain ?? $domain)) : $domain,
                'recorded_at' => (string) ($row->recorded_at ?? ''),
                'external_id' => (string) ($row->id ?? ''),
                'value' => $value,
                'confidence' => (float) ($row->confidence ?? 1.0),
                'user_id' => $row->user_id ?? null,
                'source' => (string) ($row->source ?? ''),
            ];
        }

        return $out;
    }
}
