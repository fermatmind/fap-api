<?php

namespace App\Support\Idempotency;

use Illuminate\Support\Facades\DB;

class IdempotencyStore
{
    public function find(string $provider, string $externalId, string $recordedAt, string $hash): ?array
    {
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

    public function findByIdentity(string $provider, string $externalId, string $recordedAt): ?array
    {
        $row = DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('recorded_at', $recordedAt)
            ->first();

        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public function touch(string $provider, string $externalId, string $recordedAt, string $hash): void
    {
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

    public function touchByIdentity(string $provider, string $externalId, string $recordedAt): void
    {
        DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('recorded_at', $recordedAt)
            ->update([
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function record(array $payload): array
    {
        $provider = (string) ($payload['provider'] ?? '');
        $externalId = (string) ($payload['external_id'] ?? '');
        $recordedAt = (string) ($payload['recorded_at'] ?? '');
        $hash = (string) ($payload['hash'] ?? '');
        $ingestBatchId = $payload['ingest_batch_id'] ?? null;

        $existing = $this->findByIdentity($provider, $externalId, $recordedAt);
        if ($existing) {
            $this->touchByIdentity($provider, $externalId, $recordedAt);

            return [
                'inserted' => false,
                'existing' => true,
                'hash_mismatch' => trim((string) ($existing['hash'] ?? '')) !== $hash,
            ];
        }

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

        if ($affected > 0) {
            return ['inserted' => true, 'existing' => false, 'hash_mismatch' => false];
        }

        $current = $this->findByIdentity($provider, $externalId, $recordedAt);
        $this->touchByIdentity($provider, $externalId, $recordedAt);

        return [
            'inserted' => false,
            'existing' => true,
            'hash_mismatch' => trim((string) ($current['hash'] ?? '')) !== $hash,
        ];
    }

    public function recordFast(array $payload): array
    {
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

        if (!$inserted) {
            $current = $this->findByIdentity($provider, $externalId, $recordedAt);
            $this->touchByIdentity($provider, $externalId, $recordedAt);
        }

        return [
            'inserted' => $inserted,
            'existing' => !$inserted,
            'hash_mismatch' => !$inserted
                ? trim((string) ($current['hash'] ?? '')) !== $hash
                : false,
        ];
    }

    public function recordFastBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return (int) DB::table('idempotency_keys')->insertOrIgnore($rows);
    }

    /**
     * @param array<int, string> $externalIds
     * @return array<int, string>
     */
    public function pluckInsertedExternalIds(string $provider, string $runId, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique(array_map(static fn ($id) => trim((string) $id), $externalIds)));
        $uniqueIds = array_values(array_filter($uniqueIds, static fn (string $id): bool => $id !== ''));
        if ($uniqueIds === []) {
            return [];
        }

        return DB::table('idempotency_keys')
            ->where('provider', $provider)
            ->where('run_id', $runId)
            ->whereIn('external_id', $uniqueIds)
            ->pluck('external_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
