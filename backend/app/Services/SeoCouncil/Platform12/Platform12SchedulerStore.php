<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12SchedulerStore
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    private const TERMINAL_STATES = ['CLOSED', 'FAILED', 'HELD'];

    private const LOW_PRIORITIES = ['normal', 'low'];

    public function __construct(private Platform12SchedulerVersionVector $versionVector) {}

    /** @return array<string, mixed> */
    public function acquire(string $leaseKey, string $ownerToken, ?int $ttlSeconds = null): array
    {
        $this->validateLeaseIdentity($leaseKey, $ownerToken);
        $ttl = $this->ttl($ttlSeconds);

        try {
            return $this->connection()->transaction(function () use ($leaseKey, $ownerToken, $ttl): array {
                $connection = $this->connection();
                $now = $this->databaseNow($connection);
                $ownerHash = $this->ownerHash($ownerToken);
                $expiresAt = $now->addSeconds($ttl);
                $timestamps = $this->timestamp($now);

                $inserted = $connection->table('seo_council_scheduler_leases')->insertOrIgnore([
                    'lease_key' => $leaseKey,
                    'owner_token_hash' => $ownerHash,
                    'fencing_token' => 1,
                    'lease_expires_at' => $this->timestamp($expiresAt),
                    'created_at' => $timestamps,
                    'updated_at' => $timestamps,
                ]);
                if ($inserted === 1) {
                    return $this->leaseDecision('LEASE_ACQUIRED', true, $leaseKey, 1, $expiresAt);
                }

                $lease = $connection->table('seo_council_scheduler_leases')
                    ->where('lease_key', $leaseKey)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($lease)) {
                    return $this->leaseDecision('LOCK_STORE_UNAVAILABLE', false, $leaseKey);
                }

                $existingExpiry = $this->parseTimestamp((string) $lease->lease_expires_at);
                if ($this->futureClockDrift($existingExpiry, $now)) {
                    return $this->leaseDecision('CLOCK_DRIFT_HOLD', false, $leaseKey);
                }
                if ($existingExpiry->isAfter($now)) {
                    if (hash_equals((string) $lease->owner_token_hash, $ownerHash)) {
                        return $this->leaseDecision(
                            'LEASE_REPLAY',
                            true,
                            $leaseKey,
                            (int) $lease->fencing_token,
                            $existingExpiry,
                        );
                    }

                    return $this->leaseDecision('LOCK_HELD', false, $leaseKey);
                }

                $currentToken = (int) $lease->fencing_token;
                if ($currentToken >= PHP_INT_MAX) {
                    return $this->leaseDecision('FENCING_EXHAUSTED_HOLD', false, $leaseKey);
                }
                $nextToken = $currentToken + 1;
                $updated = $connection->table('seo_council_scheduler_leases')
                    ->where('id', (int) $lease->id)
                    ->where('fencing_token', $currentToken)
                    ->update([
                        'owner_token_hash' => $ownerHash,
                        'fencing_token' => $nextToken,
                        'lease_expires_at' => $this->timestamp($expiresAt),
                        'updated_at' => $timestamps,
                    ]);

                return $updated === 1
                    ? $this->leaseDecision('LEASE_ACQUIRED', true, $leaseKey, $nextToken, $expiresAt)
                    : $this->leaseDecision('LOCK_HELD', false, $leaseKey);
            });
        } catch (Throwable) {
            return $this->leaseDecision('LOCK_STORE_UNAVAILABLE', false, $leaseKey);
        }
    }

    /** @return array<string, mixed> */
    public function renew(
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        ?int $ttlSeconds = null,
    ): array {
        $this->validateLeaseIdentity($leaseKey, $ownerToken);
        $ttl = $this->ttl($ttlSeconds);

        try {
            return $this->connection()->transaction(function () use ($leaseKey, $ownerToken, $fencingToken, $ttl): array {
                $connection = $this->connection();
                $now = $this->databaseNow($connection);
                $decision = $this->currentFence($connection, $leaseKey, $ownerToken, $fencingToken, $now);
                if ($decision['status'] !== 'FENCE_CURRENT') {
                    return $this->leaseDecision((string) $decision['status'], false, $leaseKey);
                }

                $expiresAt = $now->addSeconds($ttl);
                $updated = $connection->table('seo_council_scheduler_leases')
                    ->where('id', (int) $decision['lease']->id)
                    ->where('owner_token_hash', $this->ownerHash($ownerToken))
                    ->where('fencing_token', $fencingToken)
                    ->update([
                        'lease_expires_at' => $this->timestamp($expiresAt),
                        'updated_at' => $this->timestamp($now),
                    ]);

                return $updated === 1
                    ? $this->leaseDecision('LEASE_RENEWED', true, $leaseKey, $fencingToken, $expiresAt)
                    : $this->leaseDecision('STALE_FENCE', false, $leaseKey);
            });
        } catch (Throwable) {
            return $this->leaseDecision('LOCK_STORE_UNAVAILABLE', false, $leaseKey);
        }
    }

    /** @return array<string, mixed> */
    public function release(string $leaseKey, string $ownerToken, int $fencingToken): array
    {
        $this->validateLeaseIdentity($leaseKey, $ownerToken);

        try {
            return $this->connection()->transaction(function () use ($leaseKey, $ownerToken, $fencingToken): array {
                $connection = $this->connection();
                $now = $this->databaseNow($connection);
                $decision = $this->currentFence($connection, $leaseKey, $ownerToken, $fencingToken, $now);
                if ($decision['status'] !== 'FENCE_CURRENT') {
                    return $this->leaseDecision((string) $decision['status'], false, $leaseKey);
                }

                $updated = $connection->table('seo_council_scheduler_leases')
                    ->where('id', (int) $decision['lease']->id)
                    ->where('owner_token_hash', $this->ownerHash($ownerToken))
                    ->where('fencing_token', $fencingToken)
                    ->update([
                        'lease_expires_at' => $this->timestamp($now),
                        'updated_at' => $this->timestamp($now),
                    ]);

                return $this->leaseDecision(
                    $updated === 1 ? 'LEASE_RELEASED' : 'STALE_FENCE',
                    false,
                    $leaseKey,
                    $fencingToken,
                    $now,
                );
            });
        } catch (Throwable) {
            return $this->leaseDecision('LOCK_STORE_UNAVAILABLE', false, $leaseKey);
        }
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @param  array<string, mixed>  $vector
     * @return array<string, mixed>
     */
    public function reserveDelivery(array $delivery, array $vector): array
    {
        $normalizedVector = $this->versionVector->normalize($vector);
        $payload = $this->deliveryPayload(
            $delivery,
            $this->versionVector->hash($normalizedVector),
            $normalizedVector,
        );

        try {
            return $this->connection()->transaction(function () use ($payload): array {
                $connection = $this->connection();
                if ($connection->table('seo_council_schedule_deliveries')->insertOrIgnore($payload) === 1) {
                    return $this->deliveryDecision('DELIVERY_RESERVED', true, (string) $payload['delivery_id']);
                }

                $existing = $connection->table('seo_council_schedule_deliveries')
                    ->where('idempotency_key', (string) $payload['idempotency_key'])
                    ->orWhere(function ($query) use ($payload): void {
                        $query->where('catalog_hash', (string) $payload['catalog_hash'])
                            ->where('mission_id', (string) $payload['mission_id'])
                            ->where('slot_key', (string) $payload['slot_key']);
                    })
                    ->lockForUpdate()
                    ->first();
                if (! is_object($existing)) {
                    return $this->deliveryDecision('DELIVERY_STORE_UNAVAILABLE', false, (string) $payload['delivery_id']);
                }

                $same = hash_equals((string) $existing->mission_request_hash, (string) $payload['mission_request_hash'])
                    && hash_equals((string) $existing->version_vector_hash, (string) $payload['version_vector_hash']);

                return $this->deliveryDecision(
                    $same ? 'DELIVERY_REPLAY' : 'DUPLICATE_DELIVERY_HOLD',
                    $same,
                    (string) $existing->delivery_id,
                );
            });
        } catch (Throwable) {
            return $this->deliveryDecision('DELIVERY_STORE_UNAVAILABLE', false, (string) ($delivery['delivery_id'] ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $vector
     * @return array<string, mixed>
     */
    public function claimDelivery(
        string $deliveryId,
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        array $vector,
    ): array {
        $vectorHash = $this->versionVector->hash($vector);

        return $this->mutateDeliveryWithFence(
            $deliveryId,
            $leaseKey,
            $ownerToken,
            $fencingToken,
            function (ConnectionInterface $connection, object $delivery, CarbonImmutable $now) use ($leaseKey, $fencingToken, $vectorHash): array {
                if (in_array((string) $delivery->status, self::TERMINAL_STATES, true)) {
                    return $this->deliveryDecision('DELIVERY_TERMINAL', false, (string) $delivery->delivery_id);
                }
                if ($delivery->version_vector_hash !== null
                    && ! $this->storedVectorMatches($delivery, $vectorHash)) {
                    return $this->deliveryDecision('VERSION_VECTOR_HOLD', false, (string) $delivery->delivery_id);
                }
                if ((string) $delivery->status === 'CLAIMED'
                    && (string) $delivery->lease_key === $leaseKey
                    && (int) $delivery->fencing_token === $fencingToken) {
                    return $this->deliveryDecision('CLAIM_REPLAY', true, (string) $delivery->delivery_id);
                }
                if ((string) $delivery->status !== 'PLANNED') {
                    return $this->deliveryDecision('DELIVERY_NOT_CLAIMABLE', false, (string) $delivery->delivery_id);
                }

                $updated = $connection->table('seo_council_schedule_deliveries')
                    ->where('id', (int) $delivery->id)
                    ->where('status', 'PLANNED')
                    ->update([
                        'lease_key' => $leaseKey,
                        'fencing_token' => $fencingToken,
                        'version_vector_hash' => $vectorHash,
                        'status' => 'CLAIMED',
                        'updated_at' => $this->timestamp($now),
                    ]);

                return $this->deliveryDecision(
                    $updated === 1 ? 'DELIVERY_CLAIMED' : 'STALE_DELIVERY',
                    $updated === 1,
                    (string) $delivery->delivery_id,
                );
            },
        );
    }

    /**
     * @param  array<string, mixed>  $vector
     * @return array<string, mixed>
     */
    public function recoverStaleDelivery(
        string $deliveryId,
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        array $vector,
    ): array {
        $vectorHash = $this->versionVector->hash($vector);

        return $this->mutateDeliveryWithFence(
            $deliveryId,
            $leaseKey,
            $ownerToken,
            $fencingToken,
            function (ConnectionInterface $connection, object $delivery, CarbonImmutable $now) use ($leaseKey, $fencingToken, $vectorHash): array {
                if (in_array((string) $delivery->status, self::TERMINAL_STATES, true)) {
                    return $this->deliveryDecision('DELIVERY_TERMINAL', false, (string) $delivery->delivery_id);
                }
                if (! in_array((string) $delivery->status, ['CLAIMED', 'RECOVERED'], true)) {
                    return $this->deliveryDecision('STALE_RECOVERY_HOLD', false, (string) $delivery->delivery_id);
                }
                if (! $this->storedVectorMatches($delivery, $vectorHash)) {
                    return $this->deliveryDecision('VERSION_VECTOR_HOLD', false, (string) $delivery->delivery_id);
                }
                if ($delivery->fencing_token === null || (int) $delivery->fencing_token >= $fencingToken) {
                    return $this->deliveryDecision('STALE_RECOVERY_HOLD', false, (string) $delivery->delivery_id);
                }

                $updated = $connection->table('seo_council_schedule_deliveries')
                    ->where('id', (int) $delivery->id)
                    ->where('fencing_token', (int) $delivery->fencing_token)
                    ->whereNotIn('status', self::TERMINAL_STATES)
                    ->update([
                        'lease_key' => $leaseKey,
                        'fencing_token' => $fencingToken,
                        'attempt' => (int) $delivery->attempt + 1,
                        'status' => 'RECOVERED',
                        'updated_at' => $this->timestamp($now),
                    ]);

                return $this->deliveryDecision(
                    $updated === 1 ? 'DELIVERY_RECOVERED' : 'STALE_RECOVERY_HOLD',
                    $updated === 1,
                    (string) $delivery->delivery_id,
                );
            },
        );
    }

    /** @return array<string, mixed> */
    public function completeDelivery(
        string $deliveryId,
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        string $terminalReceiptReference,
        string $terminalReceiptHash,
        string $terminalStatus = 'CLOSED',
    ): array {
        if (! in_array($terminalStatus, self::TERMINAL_STATES, true)
            || preg_match(self::HASH_PATTERN, $terminalReceiptHash) !== 1
            || trim($terminalReceiptReference) === ''
            || strlen($terminalReceiptReference) > 191) {
            throw new InvalidArgumentException('SCHEDULER_TERMINAL_RECEIPT_INVALID');
        }

        try {
            return $this->mutateDeliveryWithFence(
                $deliveryId,
                $leaseKey,
                $ownerToken,
                $fencingToken,
                function (ConnectionInterface $connection, object $delivery, CarbonImmutable $now) use ($leaseKey, $fencingToken, $terminalReceiptReference, $terminalReceiptHash, $terminalStatus): array {
                    if (in_array((string) $delivery->status, self::TERMINAL_STATES, true)) {
                        $same = hash_equals((string) $delivery->terminal_receipt_hash, $terminalReceiptHash)
                            && hash_equals((string) $delivery->terminal_receipt_reference, $terminalReceiptReference);

                        return $this->terminalDecision(
                            $same ? 'TERMINAL_REPLAY' : 'TERMINAL_CONFLICT',
                            false,
                            (string) $delivery->delivery_id,
                        );
                    }
                    if (! in_array((string) $delivery->status, ['CLAIMED', 'RECOVERED'], true)
                        || (string) $delivery->lease_key !== $leaseKey
                        || (int) $delivery->fencing_token !== $fencingToken) {
                        return $this->terminalDecision('STALE_FENCE', false, (string) $delivery->delivery_id);
                    }

                    $receiptOwner = $connection->table('seo_council_schedule_deliveries')
                        ->where('terminal_receipt_hash', $terminalReceiptHash)
                        ->lockForUpdate()
                        ->first();
                    if (is_object($receiptOwner) && (int) $receiptOwner->id !== (int) $delivery->id) {
                        return $this->terminalDecision('TERMINAL_CONFLICT', false, (string) $delivery->delivery_id);
                    }

                    $updated = $connection->table('seo_council_schedule_deliveries')
                        ->where('id', (int) $delivery->id)
                        ->where('lease_key', $leaseKey)
                        ->where('fencing_token', $fencingToken)
                        ->whereIn('status', ['CLAIMED', 'RECOVERED'])
                        ->update([
                            'status' => $terminalStatus,
                            'terminal_receipt_reference' => $terminalReceiptReference,
                            'terminal_receipt_hash' => $terminalReceiptHash,
                            'updated_at' => $this->timestamp($now),
                        ]);

                    return $this->terminalDecision(
                        $updated === 1 ? 'TERMINAL_COMMITTED' : 'STALE_FENCE',
                        $updated === 1,
                        (string) $delivery->delivery_id,
                    );
                },
                true,
            );
        } catch (QueryException) {
            return $this->terminalDecision('TERMINAL_CONFLICT', false, $deliveryId);
        }
    }

    /** @return array<string, mixed> */
    public function applyBackpressure(
        string $deliveryId,
        string $priority,
        ?string $previousSlotKey,
        ?int $queueLimit,
        bool $dependenciesAvailable,
    ): array {
        if (! in_array($priority, ['critical', 'high', 'normal', 'low'], true)) {
            throw new InvalidArgumentException('SCHEDULER_PRIORITY_INVALID');
        }
        $limit = $queueLimit ?? (int) config('seo_council.scheduler_queue_limit', 64);
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('SCHEDULER_QUEUE_LIMIT_INVALID');
        }

        try {
            return $this->connection()->transaction(function () use ($deliveryId, $priority, $previousSlotKey, $limit, $dependenciesAvailable): array {
                $connection = $this->connection();
                $lowPriority = in_array($priority, self::LOW_PRIORITIES, true);
                $reason = null;

                if (! $dependenciesAvailable) {
                    $reason = 'DEPENDENCY_UNAVAILABLE';
                } elseif ($previousSlotKey !== null) {
                    $previous = $connection->table('seo_council_schedule_receipts')
                        ->where('slot_key', $previousSlotKey)
                        ->orderByDesc('id')
                        ->first();
                    if (! is_object($previous) || (string) $previous->status !== 'CLOSED') {
                        $reason = 'PREVIOUS_SLOT_OPEN';
                    }
                }

                if ($reason === null) {
                    $queued = $connection->table('seo_council_schedule_deliveries')
                        ->whereIn('status', ['PLANNED', 'CLAIMED', 'RECOVERED'])
                        ->count();
                    if ($queued > $limit) {
                        $reason = 'QUEUE_LIMIT_EXCEEDED';
                    }
                }

                if ($reason === null || ! $lowPriority) {
                    return [
                        'status' => $reason === 'DEPENDENCY_UNAVAILABLE' ? 'DEPENDENCY_HOLD' : 'READY',
                        'reason' => $reason,
                        'delivery_id' => $deliveryId,
                    ];
                }

                $connection->table('seo_council_schedule_deliveries')
                    ->where('delivery_id', $deliveryId)
                    ->whereNotIn('status', self::TERMINAL_STATES)
                    ->update(['status' => 'BACKPRESSURE_HOLD', 'updated_at' => $this->timestamp($this->databaseNow($connection))]);

                return ['status' => 'BACKPRESSURE_HOLD', 'reason' => $reason, 'delivery_id' => $deliveryId];
            });
        } catch (Throwable) {
            return ['status' => 'BACKPRESSURE_HOLD', 'reason' => 'SHARED_STORE_UNAVAILABLE', 'delivery_id' => $deliveryId];
        }
    }

    /**
     * @param  callable(ConnectionInterface, object, CarbonImmutable): array<string, mixed>  $mutation
     * @return array<string, mixed>
     */
    private function mutateDeliveryWithFence(
        string $deliveryId,
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        callable $mutation,
        bool $rethrowQueryException = false,
    ): array {
        $this->validateLeaseIdentity($leaseKey, $ownerToken);
        if (preg_match(self::HASH_PATTERN, $deliveryId) !== 1 || $fencingToken < 1) {
            throw new InvalidArgumentException('SCHEDULER_DELIVERY_FENCE_INVALID');
        }

        try {
            return $this->connection()->transaction(function () use ($deliveryId, $leaseKey, $ownerToken, $fencingToken, $mutation): array {
                $connection = $this->connection();
                $now = $this->databaseNow($connection);
                $fence = $this->currentFence($connection, $leaseKey, $ownerToken, $fencingToken, $now);
                if ($fence['status'] !== 'FENCE_CURRENT') {
                    return $this->deliveryDecision((string) $fence['status'], false, $deliveryId);
                }

                $delivery = $connection->table('seo_council_schedule_deliveries')
                    ->where('delivery_id', $deliveryId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($delivery)) {
                    return $this->deliveryDecision('DELIVERY_NOT_FOUND', false, $deliveryId);
                }

                return $mutation($connection, $delivery, $now);
            });
        } catch (QueryException $exception) {
            if ($rethrowQueryException) {
                throw $exception;
            }

            return $this->deliveryDecision('LOCK_STORE_UNAVAILABLE', false, $deliveryId);
        } catch (Throwable) {
            return $this->deliveryDecision('LOCK_STORE_UNAVAILABLE', false, $deliveryId);
        }
    }

    /** @return array{status:string, lease?:object} */
    private function currentFence(
        ConnectionInterface $connection,
        string $leaseKey,
        string $ownerToken,
        int $fencingToken,
        CarbonImmutable $now,
    ): array {
        $lease = $connection->table('seo_council_scheduler_leases')
            ->where('lease_key', $leaseKey)
            ->lockForUpdate()
            ->first();
        if (! is_object($lease)) {
            return ['status' => 'STALE_FENCE'];
        }
        $expiresAt = $this->parseTimestamp((string) $lease->lease_expires_at);
        if ($this->futureClockDrift($expiresAt, $now)) {
            return ['status' => 'CLOCK_DRIFT_HOLD'];
        }
        if (! hash_equals((string) $lease->owner_token_hash, $this->ownerHash($ownerToken))
            || (int) $lease->fencing_token !== $fencingToken
            || ! $expiresAt->isAfter($now)) {
            return ['status' => 'STALE_FENCE'];
        }

        return ['status' => 'FENCE_CURRENT', 'lease' => $lease];
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @param  array<string, string>  $normalizedVector
     */
    private function deliveryPayload(
        array $delivery,
        string $versionVectorHash,
        array $normalizedVector,
    ): array {
        foreach (['delivery_id', 'catalog_hash', 'mission_request_hash'] as $field) {
            if (preg_match(self::HASH_PATTERN, (string) ($delivery[$field] ?? '')) !== 1) {
                throw new InvalidArgumentException('SCHEDULER_DELIVERY_INVALID');
            }
        }
        foreach (['slot_key', 'catalog_version', 'mission_id', 'idempotency_key', 'scheduled_for'] as $field) {
            if (trim((string) ($delivery[$field] ?? '')) === '') {
                throw new InvalidArgumentException('SCHEDULER_DELIVERY_INVALID');
            }
        }
        if (! is_array($delivery['mission_request'] ?? null)) {
            throw new InvalidArgumentException('SCHEDULER_DELIVERY_INVALID');
        }

        $now = CarbonImmutable::now('UTC');

        return [
            'delivery_id' => strtolower((string) $delivery['delivery_id']),
            'slot_key' => (string) $delivery['slot_key'],
            'scheduled_for' => $this->timestamp(CarbonImmutable::parse((string) $delivery['scheduled_for'], 'UTC')),
            'catalog_version' => (string) $delivery['catalog_version'],
            'catalog_hash' => strtolower((string) $delivery['catalog_hash']),
            'mission_id' => (string) $delivery['mission_id'],
            'mission_request_hash' => strtolower((string) $delivery['mission_request_hash']),
            'version_vector_hash' => $versionVectorHash,
            'version_vector_json' => json_encode(
                $normalizedVector,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'mission_request_json' => json_encode(
                $delivery['mission_request'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'idempotency_key' => (string) $delivery['idempotency_key'],
            'attempt' => 1,
            'status' => 'PLANNED',
            'created_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
        ];
    }

    private function storedVectorMatches(object $delivery, string $expectedHash): bool
    {
        if ($delivery->version_vector_hash === null
            || $delivery->version_vector_json === null
            || ! hash_equals((string) $delivery->version_vector_hash, $expectedHash)) {
            return false;
        }

        try {
            $stored = json_decode((string) $delivery->version_vector_json, true, flags: JSON_THROW_ON_ERROR);

            return is_array($stored)
                && hash_equals($this->versionVector->hash($stored), $expectedHash);
        } catch (Throwable) {
            return false;
        }
    }

    private function validateLeaseIdentity(string $leaseKey, string $ownerToken): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/D', $leaseKey) !== 1
            || strlen($ownerToken) < 16
            || strlen($ownerToken) > 256) {
            throw new InvalidArgumentException('SCHEDULER_LEASE_IDENTITY_INVALID');
        }
    }

    private function ttl(?int $ttlSeconds): int
    {
        $ttl = $ttlSeconds ?? (int) config('seo_council.scheduler_lease_ttl_seconds', 120);
        if ($ttl < 1 || $ttl > (int) config('seo_council.scheduler_max_lease_ttl_seconds', 300)) {
            throw new InvalidArgumentException('SCHEDULER_LEASE_TTL_INVALID');
        }

        return $ttl;
    }

    private function futureClockDrift(CarbonImmutable $expiresAt, CarbonImmutable $now): bool
    {
        $maxFuture = (int) config('seo_council.scheduler_max_lease_ttl_seconds', 300)
            + (int) config('seo_council.scheduler_max_clock_drift_seconds', 30);

        return $expiresAt->isAfter($now->addSeconds($maxFuture));
    }

    private function databaseNow(ConnectionInterface $connection): CarbonImmutable
    {
        $row = $connection->selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
        if (! is_object($row) || trim((string) ($row->current_time ?? '')) === '') {
            throw new InvalidArgumentException('SCHEDULER_DATABASE_CLOCK_UNAVAILABLE');
        }

        return $this->parseTimestamp((string) $row->current_time);
    }

    private function parseTimestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function timestamp(CarbonImmutable $value): string
    {
        return $value->utc()->format('Y-m-d H:i:s');
    }

    private function ownerHash(string $ownerToken): string
    {
        return hash('sha256', $ownerToken);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection((string) config('seo_council.connection', 'seo_intel'));
    }

    /** @return array<string, mixed> */
    private function leaseDecision(
        string $status,
        bool $acquired,
        string $leaseKey,
        ?int $fencingToken = null,
        ?CarbonImmutable $expiresAt = null,
    ): array {
        return [
            'status' => $status,
            'acquired' => $acquired,
            'lease_key' => $leaseKey,
            'fencing_token' => $fencingToken,
            'lease_expires_at' => $expiresAt?->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private function deliveryDecision(string $status, bool $accepted, string $deliveryId): array
    {
        return ['status' => $status, 'accepted' => $accepted, 'delivery_id' => $deliveryId];
    }

    /** @return array<string, mixed> */
    private function terminalDecision(string $status, bool $committed, string $deliveryId): array
    {
        return [
            'status' => $status,
            'terminal_committed' => $committed,
            'delivery_id' => $deliveryId,
        ];
    }
}
