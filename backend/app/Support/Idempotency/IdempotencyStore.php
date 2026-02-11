<?php

namespace App\Support\Idempotency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IdempotencyStore
{
    public function find(string $provider, string $externalId, string $recordedAt, string $hash): ?array
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return null;
        }

        $row = DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('recorded_at', $recordedAt)
            ->where('hash', $hash)
            ->first();

        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public function findByPayload(string $provider, string $recordedAt, string $hash): ?array
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return null;
        }

        $row = DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('recorded_at', $recordedAt)
            ->where('hash', $hash)
            ->first();

        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public function touch(string $provider, string $externalId, string $recordedAt, string $hash): void
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return;
        }

        DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('recorded_at', $recordedAt)
            ->where('hash', $hash)
            ->update([
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function record(array $payload): array
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return ['inserted' => false, 'existing' => false];
        }

        $provider = (string) ($payload['provider'] ?? '');
        $externalId = (string) ($payload['external_id'] ?? '');
        $recordedAt = (string) ($payload['recorded_at'] ?? '');
        $hash = (string) ($payload['hash'] ?? '');
        $ingestBatchId = $payload['ingest_batch_id'] ?? null;

        $existing = $this->find($provider, $externalId, $recordedAt, $hash);
        if ($existing) {
            $this->touch($provider, $externalId, $recordedAt, $hash);
            return ['inserted' => false, 'existing' => true];
        }

        $now = now();
        DB::table('idempotency_keys')->insert([
            'provider' => $provider,
            'external_id' => $externalId,
            'recorded_at' => $recordedAt,
            'hash' => $hash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'ingest_batch_id' => $ingestBatchId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['inserted' => true, 'existing' => false];
    }

    public function recordFast(array $payload): array
    {
        if (!Schema::hasTable('idempotency_keys')) {
            return ['inserted' => false, 'existing' => false];
        }

        $provider = (string) ($payload['provider'] ?? '');
        $externalId = (string) ($payload['external_id'] ?? '');
        $recordedAt = (string) ($payload['recorded_at'] ?? '');
        $hash = (string) ($payload['hash'] ?? '');
        $ingestBatchId = $payload['ingest_batch_id'] ?? null;

        $now = now();
        $affected = DB::table('idempotency_keys')->insertOrIgnore([
            'provider' => $provider,
            'external_id' => $externalId,
            'recorded_at' => $recordedAt,
            'hash' => $hash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'ingest_batch_id' => $ingestBatchId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inserted = $affected > 0;

        return [
            'inserted' => $inserted,
            'existing' => !$inserted,
        ];
    }
}
