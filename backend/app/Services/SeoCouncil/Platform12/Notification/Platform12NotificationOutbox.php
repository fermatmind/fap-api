<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12NotificationOutbox
{
    private const STATES = ['pending', 'sending', 'sent', 'failed', 'suppressed'];

    public function __construct(
        private Platform12NotificationTransport $transport,
        private PolicyGatewayPrivacyGuard $privacy,
    ) {}

    /** @param array<string, mixed> $classification @return array<string, mixed> */
    public function enqueue(array $classification, string $incidentState, string $missionVerdict): array
    {
        $this->validateMissionVerdict($missionVerdict);
        if (($classification['status'] ?? null) !== 'PASS'
            || ($classification['immediate_notification_candidate'] ?? null) !== true
            || ! is_array($classification['sanitized_event'] ?? null)) {
            return $this->enqueueResult('suppressed', 'NON_IMMEDIATE_EVENT', null, $missionVerdict);
        }
        $event = $classification['sanitized_event'];
        $this->validateEvent($event, $incidentState);

        return $this->insert($event, strtolower($incidentState), $missionVerdict);
    }

    /** @param list<array{id:string,hash:string}> $evidenceRefs @return array<string, mixed> */
    public function enqueueRecovery(
        string $eventType,
        string $subjectHash,
        string $policyRevision,
        array $evidenceRefs,
        string $expiresAt,
        string $missionVerdict,
    ): array {
        $this->validateMissionVerdict($missionVerdict);
        $this->validateIdentity($eventType, $subjectHash, $policyRevision);

        try {
            return $this->connection()->transaction(function () use (
                $eventType,
                $subjectHash,
                $policyRevision,
                $evidenceRefs,
                $expiresAt,
                $missionVerdict,
            ): array {
                $connection = $this->connection();
                $failedExists = $connection->table('seo_council_notification_outbox')
                    ->where('event_type', $eventType)
                    ->where('subject_hash', $subjectHash)
                    ->where('incident_state', 'failed')
                    ->lockForUpdate()
                    ->exists();
                if (! $failedExists) {
                    return $this->enqueueResult('suppressed', 'RECOVERY_WITHOUT_FAILURE', null, $missionVerdict);
                }

                $event = [
                    'event_type' => $eventType.'_RECOVERY',
                    'severity' => 'INFO',
                    'subject_hash' => $subjectHash,
                    'evidence_refs' => $evidenceRefs,
                    'policy_revision' => $policyRevision,
                    'state' => 'RESOLVED',
                    'expires_at' => $expiresAt,
                ];
                $this->validateEvent($event, 'healthy');

                return $this->insertOnConnection($connection, $event, 'healthy', $missionVerdict);
            });
        } catch (Throwable) {
            return $this->enqueueResult('suppressed', 'OUTBOX_UNAVAILABLE', null, $missionVerdict);
        }
    }

    /** @return array<string, mixed> */
    public function claim(string $workerToken, ?int $leaseSeconds = null): array
    {
        $this->validateWorkerToken($workerToken);
        $ttl = $leaseSeconds ?? (int) config('seo_council.notification_lease_seconds', 60);
        if ($ttl < 1 || $ttl > (int) config('seo_council.notification_max_lease_seconds', 300)) {
            throw new InvalidArgumentException('NOTIFICATION_LEASE_INVALID');
        }

        try {
            return $this->connection()->transaction(function () use ($workerToken, $ttl): array {
                $connection = $this->connection();
                $now = $this->databaseNow($connection);
                $timestamp = $this->timestamp($now);
                $row = $connection->table('seo_council_notification_outbox')
                    ->where(function ($query) use ($timestamp): void {
                        $query->where(function ($pending) use ($timestamp): void {
                            $pending->where('status', 'pending')->where('available_at', '<=', $timestamp);
                        })->orWhere(function ($expired) use ($timestamp): void {
                            $expired->where('status', 'sending')->where('lease_expires_at', '<=', $timestamp);
                        });
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if (! is_object($row)) {
                    return ['status' => 'EMPTY', 'claim' => null];
                }
                if ($row->status === 'sending' && $row->last_error_code === 'DISPATCH_IN_FLIGHT') {
                    // The webhook has no recipient-side idempotency contract. An
                    // interrupted send cannot safely be retried as if unsent.
                    $connection->table('seo_council_notification_outbox')->where('id', $row->id)->update([
                        'status' => 'failed', 'last_error_code' => 'DELIVERY_ACK_UNKNOWN',
                        'lease_token_hash' => null, 'lease_expires_at' => null, 'updated_at' => $timestamp,
                    ]);

                    return ['status' => 'DELIVERY_ACK_UNKNOWN', 'claim' => null];
                }
                if ((int) $row->attempt >= (int) $row->max_attempts) {
                    $connection->table('seo_council_notification_outbox')->where('id', (int) $row->id)->update([
                        'status' => 'failed',
                        'last_error_code' => 'ATTEMPT_BUDGET_EXHAUSTED',
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'updated_at' => $timestamp,
                    ]);

                    return ['status' => 'TERMINAL_FAILURE', 'claim' => null];
                }

                $claimHash = $this->claimHash($workerToken, (string) $row->notification_id);
                $updated = $connection->table('seo_council_notification_outbox')
                    ->where('id', (int) $row->id)
                    ->where('status', (string) $row->status)
                    ->where('attempt', (int) $row->attempt)
                    ->update([
                        'status' => 'sending',
                        'attempt' => (int) $row->attempt + 1,
                        'lease_token_hash' => $claimHash,
                        'lease_expires_at' => $this->timestamp($now->addSeconds($ttl)),
                        'updated_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    return ['status' => 'CLAIM_CONFLICT', 'claim' => null];
                }

                return [
                    'status' => 'CLAIMED',
                    'claim' => [
                        'notification_id' => (string) $row->notification_id,
                        'worker_token' => $workerToken,
                        'attempt' => (int) $row->attempt + 1,
                        'payload' => json_decode((string) $row->payload_json, true, 32, JSON_THROW_ON_ERROR),
                    ],
                ];
            });
        } catch (Throwable) {
            return ['status' => 'OUTBOX_UNAVAILABLE', 'claim' => null];
        }
    }

    /** @param array<string, mixed> $claim @return array<string, mixed> */
    public function dispatch(array $claim, string $missionVerdict): array
    {
        $this->validateMissionVerdict($missionVerdict);
        $notificationId = (string) ($claim['notification_id'] ?? '');
        $workerToken = (string) ($claim['worker_token'] ?? '');
        $payload = $claim['payload'] ?? null;
        $this->validateWorkerToken($workerToken);
        if (preg_match('/^[a-f0-9]{64}$/D', $notificationId) !== 1 || ! is_array($payload)) {
            throw new InvalidArgumentException('NOTIFICATION_CLAIM_INVALID');
        }
        $runtime = app(\App\Services\SeoCouncil\Platform12\Platform12RuntimeControl::class)->status();
        $dailyActive = config('seo_council.daily_read_only_enabled', false)
            && ($runtime['computation_enabled'] ?? false);
        $stagingAcceptance = app()->environment('staging')
            && ($runtime['controlled_acceptance_enabled'] ?? false)
            && ($payload['event_type'] ?? null) === 'STAGING_ACCEPTANCE';
        if ((! (bool) config('seo_council.notification_dispatch_enabled', false) && ! $dailyActive && ! $stagingAcceptance)
            || (config('seo_council.daily_read_only_enabled', false) && ! $dailyActive && ! $stagingAcceptance)) {
            return $this->dispatchResult('DISABLED', 'NOTIFICATION_DISPATCH_DISABLED', $missionVerdict);
        }

        $connection = $this->connection();
        $claimHash = $this->claimHash($workerToken, $notificationId);
        $dispatchHash = hash('sha256', $claimHash.'|dispatch');
        $now = $this->databaseNow($connection);
        $claimed = $connection->table('seo_council_notification_outbox')
            ->where('notification_id', $notificationId)
            ->where('status', 'sending')
            ->where('lease_token_hash', $claimHash)
            ->where('lease_expires_at', '>', $this->timestamp($now))
            ->update(['lease_token_hash' => $dispatchHash, 'last_error_code' => 'DISPATCH_IN_FLIGHT', 'updated_at' => $this->timestamp($now)]);
        if ($claimed !== 1) {
            return $this->dispatchResult('suppressed', 'DUPLICATE_OR_STALE_DELIVERY', $missionVerdict);
        }

        $acknowledged = false;
        try {
            $this->transport->send($notificationId, $payload);
            $acknowledged = true;
            $updated = $connection->table('seo_council_notification_outbox')
                ->where('notification_id', $notificationId)
                ->where('status', 'sending')
                ->where('lease_token_hash', $dispatchHash)
                ->update([
                    'status' => 'sent',
                    'sent_at' => $this->timestamp($this->databaseNow($connection)),
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $this->timestamp($this->databaseNow($connection)),
                ]);

            return $this->dispatchResult(
                $updated === 1 ? 'sent' : 'failed',
                $updated === 1 ? 'DELIVERED' : 'DELIVERY_STATE_LOST',
                $missionVerdict,
            );
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException) {
            $connection->table('seo_council_notification_outbox')
                ->where('notification_id', $notificationId)->where('lease_token_hash', $dispatchHash)
                ->update(['status' => 'failed', 'last_error_code' => 'DELIVERY_ACK_UNKNOWN',
                    'lease_token_hash' => null, 'lease_expires_at' => null]);

            return $this->dispatchResult('failed', 'DELIVERY_ACK_UNKNOWN', $missionVerdict);
        } catch (Throwable) {
            if ($acknowledged) {
                // The receiver already acknowledged. A local persistence failure
                // must never turn this into a second external send.
                $connection->table('seo_council_notification_outbox')
                    ->where('notification_id', $notificationId)->where('lease_token_hash', $dispatchHash)
                    ->update(['status' => 'failed', 'last_error_code' => 'DELIVERY_ACK_UNKNOWN',
                        'lease_token_hash' => null, 'lease_expires_at' => null]);

                return $this->dispatchResult('failed', 'DELIVERY_ACK_UNKNOWN', $missionVerdict);
            }

            return $this->recordFailure($connection, $notificationId, $dispatchHash, $missionVerdict);
        }
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        try {
            $connection = $this->connection();
            $counts = array_fill_keys(self::STATES, 0);
            foreach ($connection->table('seo_council_notification_outbox')
                ->selectRaw('status, COUNT(*) AS aggregate_count')
                ->groupBy('status')
                ->get() as $row) {
                if (array_key_exists((string) $row->status, $counts)) {
                    $counts[(string) $row->status] = (int) $row->aggregate_count;
                }
            }

            return [
                'state' => $counts['failed'] > 0 ? 'TERMINAL_FAILURE' : (array_sum($counts) === 0 ? 'VALID_ZERO' : 'HEALTHY'),
                'status_counts' => $counts,
                'terminal_failure_count' => $counts['failed'],
                'read_only' => true,
            ];
        } catch (Throwable) {
            return [
                'state' => 'UNAVAILABLE',
                'status_counts' => null,
                'terminal_failure_count' => null,
                'read_only' => true,
            ];
        }
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function insert(array $event, string $incidentState, string $missionVerdict): array
    {
        try {
            return $this->connection()->transaction(fn (): array => $this->insertOnConnection(
                $this->connection(),
                $event,
                $incidentState,
                $missionVerdict,
            ));
        } catch (Throwable) {
            return $this->enqueueResult('suppressed', 'OUTBOX_UNAVAILABLE', null, $missionVerdict);
        }
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function insertOnConnection(
        ConnectionInterface $connection,
        array $event,
        string $incidentState,
        string $missionVerdict,
    ): array {
        $fingerprint = hash('sha256', implode('|', [
            $event['event_type'], $event['subject_hash'], $event['policy_revision'], $incidentState,
        ]));
        $notificationId = hash('sha256', 'seo-council-notification|'.$fingerprint);
        $now = $this->databaseNow($connection);
        $inserted = $connection->table('seo_council_notification_outbox')->insertOrIgnore([
            'notification_id' => $notificationId,
            'fingerprint' => $fingerprint,
            'event_type' => $event['event_type'],
            'subject_hash' => $event['subject_hash'],
            'policy_revision' => $event['policy_revision'],
            'incident_state' => $incidentState,
            'status' => 'pending',
            'payload_json' => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attempt' => 0,
            'max_attempts' => (int) config('seo_council.notification_max_attempts', 3),
            'available_at' => $this->timestamp($now),
            'created_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
        ]);

        return $this->enqueueResult(
            $inserted === 1 ? 'pending' : 'suppressed',
            $inserted === 1 ? 'NOTIFICATION_ENQUEUED' : 'UNCHANGED_INCIDENT_SUPPRESSED',
            $notificationId,
            $missionVerdict,
        );
    }

    /** @return array<string, mixed> */
    private function recordFailure(
        ConnectionInterface $connection,
        string $notificationId,
        string $dispatchHash,
        string $missionVerdict,
    ): array {
        $row = $connection->table('seo_council_notification_outbox')
            ->where('notification_id', $notificationId)
            ->where('status', 'sending')
            ->where('lease_token_hash', $dispatchHash)
            ->first();
        if (! is_object($row)) {
            return $this->dispatchResult('suppressed', 'DELIVERY_STATE_LOST', $missionVerdict);
        }
        $terminal = (int) $row->attempt >= (int) $row->max_attempts;
        $now = $this->databaseNow($connection);
        $updated = $connection->table('seo_council_notification_outbox')
            ->where('id', (int) $row->id)
            ->where('status', 'sending')
            ->where('lease_token_hash', $dispatchHash)
            ->update([
                'status' => $terminal ? 'failed' : 'pending',
                'available_at' => $this->timestamp($now->addSeconds(min(300, 2 ** (int) $row->attempt))),
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'last_error_code' => $terminal ? 'TRANSPORT_RETRY_EXHAUSTED' : 'TRANSPORT_RETRY_PENDING',
                'updated_at' => $this->timestamp($now),
            ]);
        if ($updated !== 1) {
            return $this->dispatchResult('suppressed', 'DELIVERY_STATE_LOST', $missionVerdict);
        }

        return $this->dispatchResult(
            $terminal ? 'failed' : 'pending',
            $terminal ? 'TRANSPORT_RETRY_EXHAUSTED' : 'TRANSPORT_RETRY_PENDING',
            $missionVerdict,
        );
    }

    /** @param array<string, mixed> $event */
    private function validateEvent(array $event, string $incidentState): void
    {
        $this->validateIdentity(
            (string) ($event['event_type'] ?? ''),
            (string) ($event['subject_hash'] ?? ''),
            (string) ($event['policy_revision'] ?? ''),
        );
        $actual = array_keys($event);
        $expected = ['event_type', 'severity', 'subject_hash', 'evidence_refs', 'policy_revision', 'state', 'expires_at'];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || $this->privacy->containsPrivateData($event)
            || ! in_array(strtolower($incidentState), ['active', 'failed', 'degraded', 'healthy'], true)
            || ! in_array($event['severity'] ?? null, ['P0', 'P1', 'P2', 'P3', 'HOLD', 'INFO'], true)
            || ! in_array($event['state'] ?? null, ['ACTIVE', 'HOLD', 'RESOLVED', 'OBSERVED'], true)
            || ! is_array($event['evidence_refs'] ?? null)
            || ! array_is_list($event['evidence_refs'])
            || count($event['evidence_refs']) < 1
            || count($event['evidence_refs']) > 16
            || ! is_string($event['expires_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $event['expires_at']) !== 1) {
            throw new InvalidArgumentException('NOTIFICATION_EVENT_INVALID');
        }
        foreach ($event['evidence_refs'] as $reference) {
            if (! is_array($reference)
                || array_keys($reference) !== ['id', 'hash']
                || ! is_string($reference['id'])
                || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $reference['id']) !== 1
                || ! is_string($reference['hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $reference['hash']) !== 1) {
                throw new InvalidArgumentException('NOTIFICATION_EVIDENCE_REF_INVALID');
            }
        }
    }

    private function validateIdentity(string $eventType, string $subjectHash, string $policyRevision): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $eventType) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $subjectHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $policyRevision) !== 1) {
            throw new InvalidArgumentException('NOTIFICATION_IDENTITY_INVALID');
        }
    }

    private function validateWorkerToken(string $workerToken): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $workerToken) !== 1) {
            throw new InvalidArgumentException('NOTIFICATION_WORKER_TOKEN_INVALID');
        }
    }

    private function validateMissionVerdict(string $missionVerdict): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,47}$/D', $missionVerdict) !== 1) {
            throw new InvalidArgumentException('MISSION_VERDICT_INVALID');
        }
    }

    private function claimHash(string $workerToken, string $notificationId): string
    {
        return hash('sha256', $workerToken.'|'.$notificationId);
    }

    private function databaseNow(ConnectionInterface $connection): CarbonImmutable
    {
        $row = $connection->selectOne('SELECT CURRENT_TIMESTAMP AS database_time');
        if (! is_object($row) || trim((string) ($row->database_time ?? '')) === '') {
            throw new InvalidArgumentException('NOTIFICATION_DATABASE_CLOCK_UNAVAILABLE');
        }

        return CarbonImmutable::parse((string) $row->database_time, 'UTC')->utc();
    }

    private function timestamp(CarbonImmutable $value): string
    {
        return $value->utc()->format('Y-m-d H:i:s');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection((string) config('seo_council.connection', 'seo_intel'));
    }

    /** @return array<string, mixed> */
    private function enqueueResult(string $status, string $reason, ?string $notificationId, string $missionVerdict): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'notification_id' => $notificationId,
            'mission_verdict' => $missionVerdict,
            'verdict_mutated' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function dispatchResult(string $status, string $reason, string $missionVerdict): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'mission_verdict' => $missionVerdict,
            'verdict_mutated' => false,
        ];
    }
}
