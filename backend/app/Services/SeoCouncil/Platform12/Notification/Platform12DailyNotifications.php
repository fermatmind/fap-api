<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;

final readonly class Platform12DailyNotifications
{
    public function __construct(
        private Platform12NotificationPolicyContract $policy,
        private Platform12NotificationOutbox $outbox,
        private Platform12RuntimeControl $runtime,
        private Platform12NotificationEvidence $evidence,
    ) {}

    /** Called inside the fenced Council terminal transaction. */
    public function enqueue(Platform12FrozenMission $mission, array $receipt): void
    {
        if ($receipt['status'] === 'DAILY_STOPPED_HOLD') {
            return;
        }
        $context = $this->evidence->context($mission, $receipt);
        if ($context === null) {
            throw new \RuntimeException('NOTIFICATION_TERMINAL_INTEGRITY_HOLD');
        }
        $eventType = $this->evidence->eventType($context);
        if ($this->evidence->expectedAcceptanceWait($context)) {
            return;
        }
        $revision = $eventType === 'POLICY_HASH_DRIFT'
            ? $mission->envelope['version_vector']['policy'] : $this->policy->reference()['hash'];
        $refs = [['id' => 'council:daily-terminal', 'hash' => $receipt['receipt_hash']]];
        $expiry = now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z');
        $scope = $this->scope($context);
        $existing = $this->failures($context, $eventType);
        if ($eventType !== null) {
            foreach ($existing as [$row, $failure]) {
                if ($this->scope($failure) === $scope && ! $this->evidence->healthyBetween($failure, $context)) {
                    return; // Includes legacy identities and policy revisions.
                }
            }
            $subject = hash('sha256', implode('|', [$context['mission'], $scope, $eventType, $context['hash']]));
            $event = ['event_type' => $eventType,
                'severity' => in_array($eventType, ['AUTHORITY_INDEXABILITY_P0', 'PRIVATE_OR_SAFETY'], true) ? 'P0' : 'P1',
                'subject_hash' => $subject, 'evidence_refs' => $refs, 'policy_revision' => $revision,
                'state' => 'ACTIVE', 'expires_at' => $expiry, 'decision_metrics' => null];
            $result = $this->outbox->enqueue($this->policy->evaluate($event), 'failed', $receipt['status']);
            if ($result['reason_code'] === 'OUTBOX_UNAVAILABLE') {
                throw new \RuntimeException('TERMINAL_OUTBOX_UNAVAILABLE');
            }
        } elseif ($this->evidence->healthy($context)) {
            foreach ($existing as [$row, $failure]) {
                if (! $this->evidence->resolves($context, $failure)) {
                    continue;
                }
                $result = $this->outbox->enqueueRecovery($row->event_type, $row->subject_hash, $revision,
                    $refs, $expiry, $receipt['status']);
                if ($result['reason_code'] === 'OUTBOX_UNAVAILABLE') {
                    throw new \RuntimeException('TERMINAL_RECOVERY_OUTBOX_UNAVAILABLE');
                }
            }
        }
    }

    /** Stored in the existing immutable terminal receipt, including quiet waits. */
    public function classification(Platform12FrozenMission $mission, array $receipt): array
    {
        $context = $this->evidence->context($mission, $receipt);

        return ['kind' => 'notification_classification',
            'reason_code' => $context === null || in_array($context['trigger'], ['unknown', 'missed'], true)
                ? 'NOTIFICATION_SOURCE_UNVERIFIED'
                : ($this->evidence->expectedAcceptanceWait($context) ? 'ACCEPTANCE_REFRESH_NOT_DUE' : 'OPERATIONAL_POLICY_APPLIES'),
            'trigger_mode' => $context['trigger'] ?? 'unknown'];
    }

    private function scope(array $context): string
    {
        return match ($context['output']['state'] ?? '') {
            'DATA_FRESHNESS_HOLD', 'GSC_UNAVAILABLE_HOLD', 'MAPPING_FAILED_HOLD', 'WINDOW_INCOMPLETE_HOLD', 'DATA_QUALITY_HOLD' => 'gsc_scheduled_receipt:'.$context['output']['state'],
            'RUNTIME_UNAVAILABLE_HOLD', 'RUNTIME_READBACK_HOLD' => 'scheduled_runtime_probe',
            default => (string) ($context['output']['state'] ?? 'unavailable'),
        };
    }

    private function failures(array $context, ?string $type): \Generator
    {
        $query = \Illuminate\Support\Facades\DB::connection((string) config('seo_council.connection', 'seo_intel'))
            ->table('seo_council_notification_outbox')->where('incident_state', 'failed');
        if ($type !== null) {
            $query->where('event_type', $type);
        }
        foreach ($query->orderBy('id')->cursor() as $row) {
            $failure = $this->evidence->fromPayload(json_decode($row->payload_json, true, 64, JSON_THROW_ON_ERROR));
            if ($failure !== null && $failure['mission'] === $context['mission'] && $failure['at']->lte($context['at'])
                && ! $this->evidence->expectedAcceptanceWait($failure, true)) {
                yield [$row, $failure];
            }
        }
    }

    public function drain(): array
    {
        if (! $this->runtime->status()['computation_enabled']) {
            return ['status' => 'PAUSED'];
        }
        $claim = $this->outbox->claim('p12:'.bin2hex(random_bytes(16)));
        if ($claim['claim'] === null) {
            return ['status' => $claim['status']];
        }
        if (! $this->runtime->status()['computation_enabled']) {
            return ['status' => 'PAUSED'];
        }

        return $this->outbox->dispatch($claim['claim'], 'RECORDED');
    }
}
