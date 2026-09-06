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
    ) {}

    /** Called inside the fenced Council terminal transaction. */
    public function enqueue(Platform12FrozenMission $mission, array $receipt): void
    {
        if ($receipt['status'] === 'DAILY_STOPPED_HOLD') {
            return;
        }
        $evaluation = collect($receipt['route_plan'])->firstWhere('kind', 'daily_evaluation');
        $output = $evaluation['output'] ?? [];
        // Report unavailable observations honestly; notification delivery never
        // promotes a source gap to a completed wiring acceptance.
        $eventType = match ($output['state'] ?? null) {
            'WRONG_CANONICAL_HOLD' => 'AUTHORITY_INDEXABILITY_P0',
            'FALSE_NOINDEX_HOLD' => 'AUTHORITY_INDEXABILITY_P1',
            'DENY' => ($output['reason_codes'] ?? []) === ['INPUT_UNAVAILABLE'] ? null : 'PRIVATE_OR_SAFETY',
            'DATA_FRESHNESS_HOLD', 'GSC_UNAVAILABLE_HOLD', 'MAPPING_FAILED_HOLD', 'WINDOW_INCOMPLETE_HOLD', 'DATA_QUALITY_HOLD',
            'RUNTIME_UNAVAILABLE_HOLD', 'URL_TRUTH_UNAVAILABLE_HOLD', 'CLUSTER_DEDUPE_UNAVAILABLE_HOLD', 'OBSERVATION_UNAVAILABLE_HOLD', 'INPUT_HOLD' => 'DATA_FAILURE',
            'HOLD' => in_array('AUTHORITY_HASH_DRIFT_HOLD', $output['reason_codes'] ?? [], true)
                && in_array('DRIFT', $output['drift'] ?? [], true) ? 'POLICY_HASH_DRIFT'
                    : (array_intersect(['SECURITY_EVIDENCE_UNAVAILABLE', 'INPUT_UNAVAILABLE'], $output['reason_codes'] ?? []) !== [] ? 'DATA_FAILURE' : null),
            default => null,
        };
        $lastHealthy = \Illuminate\Support\Facades\DB::connection((string) config('seo_council.connection', 'seo_intel'))
            ->table('seo_council_schedule_deliveries')->where('mission_id', $mission->envelope['slot']['mission_id'])
            ->where('status', 'CLOSED')->orderByDesc('id')->value('terminal_receipt_hash');
        // Same failure episode is quiet; a later regression after recovery is new.
        $subject = hash('sha256', $mission->envelope['slot']['mission_id'].'|'.($lastHealthy ?? 'initial'));
        $revision = $this->policy->reference()['hash'];
        if ($eventType === 'POLICY_HASH_DRIFT') {
            $revision = $mission->envelope['version_vector']['policy'];
        }
        $refs = [['id' => 'council:daily-terminal', 'hash' => $receipt['receipt_hash']]];
        $expiry = now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z');
        if ($eventType !== null) {
            $event = ['event_type' => $eventType,
                'severity' => in_array($eventType, ['AUTHORITY_INDEXABILITY_P0', 'PRIVATE_OR_SAFETY'], true) ? 'P0' : 'P1',
                'subject_hash' => $subject, 'evidence_refs' => $refs, 'policy_revision' => $revision,
                'state' => 'ACTIVE', 'expires_at' => $expiry, 'decision_metrics' => null];
            $result = $this->outbox->enqueue($this->policy->evaluate($event), 'failed', $receipt['status']);
            if ($result['reason_code'] === 'OUTBOX_UNAVAILABLE') {
                throw new \RuntimeException('TERMINAL_OUTBOX_UNAVAILABLE');
            }
        } elseif ($receipt['status'] === 'DAILY_MISSION_READY') {
            foreach (['AUTHORITY_INDEXABILITY_P0', 'AUTHORITY_INDEXABILITY_P1', 'PRIVATE_OR_SAFETY', 'DATA_FAILURE', 'POLICY_HASH_DRIFT'] as $type) {
                $result = $this->outbox->enqueueRecovery($type, $subject, $revision, $refs, $expiry, $receipt['status']);
                if ($result['reason_code'] === 'OUTBOX_UNAVAILABLE') {
                    throw new \RuntimeException('TERMINAL_RECOVERY_OUTBOX_UNAVAILABLE');
                }
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
