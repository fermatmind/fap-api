<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12SystemHealthReadService
{
    private const TERMINAL = ['CLOSED', 'HELD', 'FAILED'];

    public function __construct(
        private Platform12SanitizedOperationsProjector $projector,
        private Platform12IssueExplanation $explanations,
    ) {}

    public function snapshot(?array $runtime = null): array
    {
        try {
            $runtime ??= app(Platform12RuntimeControl::class)->status();
            $connection = DB::connection((string) config('seo_council.connection', 'seo_intel'));
            $now = CarbonImmutable::now('UTC');
            // Latest record per mission plus EVERY unfinished delivery: unrelated new
            // work must never hide an old unfinished task. Refuse a truncated read.
            $latest = $connection->table('seo_council_schedule_deliveries')->selectRaw('MAX(id)')->groupBy('mission_id');
            $latestResults = $connection->table('seo_council_schedule_deliveries')->selectRaw('MAX(id)')
                ->whereIn('mission_id', Platform12DailyMissionSet::IDS)
                ->whereIn('status', self::TERMINAL)->groupBy('mission_id')
                ->groupByRaw("CASE WHEN slot_key LIKE 'a08:acceptance:%' THEN 1 ELSE 0 END");
            $rows = $connection->table('seo_council_schedule_deliveries AS d')
                ->leftJoin('seo_council_run_receipts AS r', 'd.terminal_receipt_reference', '=', 'r.receipt_id')
                ->where(fn ($query) => $query->whereNotIn('d.status', self::TERMINAL)->orWhereIn('d.id', $latest)->orWhereIn('d.id', $latestResults))
                ->select(['d.id', 'd.slot_key', 'd.mission_id', 'd.status', 'd.scheduled_for', 'd.lease_key', 'd.fencing_token',
                    'd.terminal_receipt_reference', 'd.terminal_receipt_hash', 'd.mission_request_hash',
                    'r.receipt_hash AS stored_receipt_hash'])
                ->selectRaw("CASE WHEN d.mission_id IN (?, ?, ?) AND d.status IN ('CLOSED', 'HELD', 'FAILED') AND LENGTH(d.mission_request_json) <= 131072 THEN d.mission_request_json ELSE NULL END AS mission_request_json", Platform12DailyMissionSet::IDS)
                ->selectRaw('CASE WHEN LENGTH(r.receipt_json) <= 262144 THEN r.receipt_json ELSE NULL END AS receipt_json')
                ->orderByDesc('d.id')->limit(201)->get();
            $maxLeaseWindow = (int) config('seo_council.scheduler_max_lease_ttl_seconds', 300)
                + (int) config('seo_council.scheduler_max_clock_drift_seconds', 30);
            $leaseLimit = match ($connection->getDriverName()) {
                'mysql' => 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL '.$maxLeaseWindow.' SECOND)',
                'sqlite' => "datetime(CURRENT_TIMESTAMP, '+".$maxLeaseWindow." seconds')",
                default => throw new \RuntimeException('UNPROVEN_SCHEDULER_CLOCK'),
            };
            $leases = $connection->table('seo_council_scheduler_leases')
                ->select(['lease_key', 'fencing_token'])
                ->selectRaw('CASE WHEN lease_expires_at > CURRENT_TIMESTAMP AND lease_expires_at <= '.$leaseLimit.' THEN 1 ELSE 0 END AS active')
                ->limit(201)->get()->keyBy('lease_key');
            if ($rows->count() > 200 || $leases->count() > 200) {
                return $this->unavailableSnapshot();
            }
            $daily = $this->dailyMissions($rows, $leases, $runtime, $now);
            $pending = $running = $future = $anomalies = $unknown = $workUnknown = $stale = 0;
            foreach ($rows as $row) {
                if (in_array($row->status, self::TERMINAL, true) && $rows->firstWhere('mission_id', $row->mission_id)->id !== $row->id) {
                    continue;
                }
                if ($row->status === 'PLANNED') {
                    $scheduled = Platform12OperationsTime::utcDatetime($row->scheduled_for);
                    if ($scheduled === null) {
                        $unknown++;
                        $workUnknown++;
                    } elseif ($scheduled->gt($now)) {
                        $future++;
                    } else {
                        $pending++;
                        $stale += (int) $scheduled->lt($now->subDay());
                    }
                } elseif (in_array($row->status, ['CLAIMED', 'RECOVERED'], true)) {
                    $running++;
                    $lease = $leases->get($row->lease_key);
                    if ($lease === null || ! $lease->active || (int) $lease->fencing_token !== (int) $row->fencing_token) {
                        $anomalies++;
                    }
                } elseif ($row->status === 'FAILED' || $row->status === 'BACKPRESSURE_HOLD') {
                    $anomalies++;
                } elseif ($row->status === 'HELD') {
                    $receipt = $this->receipt($row);
                    if ($receipt === null) {
                        $unknown++;
                    } elseif ($this->executionHold($receipt)) {
                        $anomalies++;
                    } elseif (($receipt['status'] ?? null) !== 'DAILY_MISSION_HOLD' || ! is_array($this->evaluation($receipt))) {
                        $unknown++;
                    }
                } elseif ($row->status !== 'CLOSED') {
                    $unknown++;
                    $workUnknown++;
                }
            }
            $workload = $pending + $running;
            $backlogState = $workUnknown > 0 ? 'UNAVAILABLE' : ($stale > 0 ? 'STALE' : ($running > 0 ? 'RUNNING' : ($workload > 0 ? 'READY' : 'VALID_ZERO')));
            $scheduler = ! config('seo_council.scheduler_enabled', false) ? 'DISABLED' : (($runtime['computation_enabled'] ?? false) ? 'READY' : 'HOLD');
            $records = [
                $this->record('scheduler', 'production_council_scheduler_'.strtolower($scheduler), $scheduler, 0),
                $this->record('lease_backlog', 'active_lease_and_delivery_backlog', $backlogState, $workUnknown > 0 ? null : $workload),
                $this->record('pending_deliveries', 'pending_deliveries', $pending ? 'READY' : 'VALID_ZERO', $pending),
                $this->record('running_deliveries', 'running_deliveries', $running ? 'RUNNING' : 'VALID_ZERO', $running),
                $this->record('scheduled_deliveries', 'scheduled_deliveries', $future ? 'READY' : 'VALID_ZERO', $future),
                $this->record('active_leases', 'active_leases', 'READY', $leases->where('active', 1)->count()),
                $this->record('execution_anomalies', 'execution_anomalies', $anomalies ? 'HOLD' : ($unknown ? 'UNAVAILABLE' : 'VALID_ZERO'), $anomalies ?: ($unknown ? null : 0)),
                $this->record('business_checks', 'business_checks', $daily['business_hold_count'] ? 'HOLD' : ($daily['evidence_issue_count'] ? 'UNAVAILABLE' : 'VALID_ZERO'), $daily['business_hold_count'] ?: ($daily['evidence_issue_count'] ? null : 0)),
                $this->record('check_evidence', 'check_evidence', $daily['evidence_issue_count'] ? 'UNAVAILABLE' : 'READY', $daily['evidence_issue_count']),
            ];
            foreach (['data_freshness', 'policy_drift', 'registry_drift', 'tool_drift', 'schema_drift', 'trace_completeness'] as $component) {
                $health = $daily['health'][$component] ?? ['state' => 'UNAVAILABLE', 'time' => null];
                $records[] = $this->record($component, $component, $health['state'], 0, $health['time']);
            }
            $notification = 'DISABLED';
            $notificationFailures = 0;
            if (($runtime['computation_enabled'] ?? false) || config('seo_council.notification_dispatch_enabled', false)) {
                try {
                    $notificationFailures = $connection->table('seo_council_notification_outbox')->where('status', 'failed')->count();
                    $notification = $notificationFailures > 0 ? 'HOLD' : 'READY';
                } catch (Throwable) {
                    $notification = 'UNAVAILABLE';
                    $notificationFailures = null;
                }
            }
            $records[] = $this->record('cost', 'model_runtime_cost_events', config('seo_council.model_runtime_enabled') ? 'HOLD' : 'VALID_ZERO', 0);
            $records[] = $this->record('notification_transport', 'notification_dispatch_'.($notification === 'DISABLED' ? 'disabled' : 'enabled'), $notification, $notificationFailures);
            $records[] = $this->record('write_guards', 'production_write_guards_closed', app(Platform12RuntimeControl::class)->businessGuardsClosed() ? 'READY' : 'HOLD', 0);

            return [...$this->projector->systemHealth(['availability' => 'AVAILABLE', 'freshness' => 'FRESH', 'records' => $records]), 'daily_missions' => $daily];
        } catch (Throwable) {
            return $this->unavailableSnapshot();
        }
    }

    public function unavailableSnapshot(): array
    {
        return $this->projector->systemHealth(['availability' => 'UNAVAILABLE', 'freshness' => 'UNKNOWN', 'records' => []]);
    }

    private function record(string $component, string $summaryCode, string $state, ?int $count, ?string $observedAt = null): array
    {
        return ['component' => $component, 'summary_code' => $summaryCode, 'state' => $state, 'count' => $count, 'observed_at' => $observedAt];
    }

    private function receipt(object $row): ?array
    {
        if (! is_string($row->receipt_json) || strlen($row->receipt_json) > 262144) {
            return null;
        }
        $receipt = json_decode($row->receipt_json, true);
        if (! is_array($receipt) || ! is_string($row->terminal_receipt_hash)
            || ($receipt['request_hash'] ?? null) !== $row->mission_request_hash
            || ($receipt['receipt_id'] ?? null) !== $row->terminal_receipt_reference
            || ($receipt['receipt_hash'] ?? null) !== $row->terminal_receipt_hash
            || $row->stored_receipt_hash !== $row->terminal_receipt_hash
            || ! hash_equals(app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'), $row->terminal_receipt_hash)) {
            return null;
        }
        $scheduled = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'scheduled_delivery');
        $instant = Platform12OperationsTime::instant($scheduled['scheduled_for'] ?? null);
        $stored = Platform12OperationsTime::utcDatetime($row->scheduled_for);
        if (($scheduled['mission_id'] ?? null) !== $row->mission_id || $instant === null || $stored === null || ! $instant->eq($stored)) {
            return null;
        }

        return $receipt;
    }

    /** Match the orchestrator's execution gates, not arbitrary HELD strings. */
    private function executionHold(array $receipt): bool
    {
        return in_array($receipt['status'] ?? null, [
            'DAILY_STOPPED_HOLD', 'POLICY_HOLD', 'DEPENDENCY_HOLD', 'STALE_RESUME_HOLD',
            'ROUTING_SCOPE_HOLD', 'MISSION_SCOPE_HOLD', 'REQUESTED_ROLE_EXPANSION_HOLD',
            'EVIDENCE_HOLD', 'PERSISTENCE_HOLD',
        ], true) || (($receipt['status'] ?? null) === 'DAILY_MISSION_HOLD'
            && in_array($receipt['stop_reason'] ?? null, [
                'daily_runtime_gate_hold', 'daily_scope_or_runtime_hold', 'daily_version_drift',
                'daily_evidence_expired', 'daily_stopped_before_commit',
            ], true));
    }

    private function evaluation(array $receipt): ?array
    {
        $output = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'daily_evaluation')['output'] ?? null;

        return is_array($output) && is_string($output['state'] ?? null) ? $output : null;
    }

    private function gateStep(array $gate, array $runtime): string
    {
        $selected = $gate['selected'] ?? null;
        $allowed = $gate['run_allowed'] ?? null;
        $pause = $runtime['pause_intent'] ?? null;
        if (! is_bool($selected) || ! is_bool($allowed) || ! in_array($pause, ['RUNNING', 'PAUSED'], true)
            || ($allowed && (! $selected || $pause === 'PAUSED' || ($runtime['public_gate'] ?? null) !== 'READY'
                || ! ($gate['acceptance_ready'] ?? false) || ! ($gate['source_accepted'] ?? false) || ! ($gate['end_to_end_accepted'] ?? false)))) {
            return 'authorization_unknown';
        }

        return match (true) {
            ! $selected => 'explicit_selection_required',
            $pause === 'PAUSED' => 'mission_paused',
            $allowed => 'natural_run_authorized',
            ($runtime['public_gate'] ?? null) !== 'READY', ! ($gate['acceptance_ready'] ?? false) => 'public_checks_required',
            ! ($gate['source_accepted'] ?? false) => 'source_acceptance_required',
            ! ($gate['end_to_end_accepted'] ?? false) => 'end_to_end_required',
            default => 'runtime_blocked',
        };
    }

    private function dailyMissions($rows, $leases, array $runtime, CarbonImmutable $now): array
    {
        $set = app(Platform12DailyMissionSet::class);
        $items = [];
        $health = [];
        $businessHolds = $evidenceIssues = $missingReceipts = 0;
        foreach ($set->missions() as $index => $mission) {
            $row = $rows->firstWhere('mission_id', $mission['mission_id']);
            $receipt = $row === null ? null : $this->receipt($row);
            $missingReceipts += (int) ($receipt === null);
            $scheduled = $receipt === null ? null : collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'scheduled_delivery');
            $output = $receipt === null ? null : $this->evaluation($receipt);
            $observed = Platform12OperationsTime::instant($output['evaluated_at'] ?? null);
            $stale = $observed !== null && $observed->lt($now->subHours(26));
            $execution = $row?->status ?? 'NOT_STARTED';
            $lease = $row === null ? null : $leases->get($row->lease_key);
            $interrupted = in_array($execution, ['CLAIMED', 'RECOVERED'], true)
                && ($lease === null || ! $lease->active || (int) $lease->fencing_token !== (int) $row->fencing_token);
            $validResult = $receipt !== null && $output !== null && empty($scheduled['source_gaps']) && ! $this->executionHold($receipt)
                && (($execution === 'CLOSED' && ($receipt['status'] ?? null) === 'DAILY_MISSION_READY' && $output['state'] === 'READY')
                    || ($execution === 'HELD' && ($receipt['status'] ?? null) === 'DAILY_MISSION_HOLD' && $output['state'] !== 'READY'));
            $state = match (true) {
                $row === null => 'NOT_STARTED',
                $interrupted => 'EXECUTION_HOLD',
                in_array($execution, ['CLAIMED', 'RECOVERED'], true) => 'RUNNING',
                $execution === 'PLANNED' => 'PENDING',
                $execution === 'FAILED' => 'FAILED',
                $execution === 'BACKPRESSURE_HOLD' => 'EXECUTION_HOLD',
                $receipt !== null && $this->executionHold($receipt) => 'EXECUTION_HOLD',
                ! $validResult || $observed === null || $observed->gt($now) => 'UNAVAILABLE',
                $stale => 'STALE',
                $output['state'] === 'READY' => 'READY',
                default => 'HOLD',
            };
            $businessHolds += (int) ($state === 'HOLD');
            $evidenceIssues += (int) in_array($state, ['STALE', 'UNAVAILABLE', 'NOT_STARTED'], true);
            $observedAt = $observed !== null && $observed->lte($now) ? Platform12OperationsTime::iso($observed) : null;
            $explanation = $this->explanations->for(in_array($state, ['READY', 'HOLD', 'STALE'], true) ? $output : [], $state, ! empty($scheduled['source_gaps']));
            $gate = $runtime['missions'][$mission['mission_id']] ?? [];
            $step = $this->gateStep($gate, $runtime);
            $items[] = [
                'label_key' => 'seo-council.missions.'.$index, 'state' => $state, ...$explanation,
                'execution_state' => $interrupted ? 'EXECUTION_INTERRUPTED' : $this->safeCode($execution),
                'business_result' => $validResult ? $this->safeCode($output['state']) : 'UNAVAILABLE',
                'source_checks' => $this->sourceChecks($scheduled, $output ?? [], $now),
                'latest_natural' => $this->latestResult($rows, $mission['mission_id'], false, $now),
                'latest_controlled' => $this->latestResult($rows, $mission['mission_id'], true, $now),
                'observed_at' => $observedAt,
                // updated_at has mixed writers (UTC reservation, DB-wall completion).
                // It is not business evaluation evidence and is not guessed here.
                'record_updated_at' => null,
                'evidence_origin' => in_array($scheduled['trigger_mode'] ?? null, ['scheduled', 'catch_up', 'missed', 'controlled_acceptance'], true) ? $scheduled['trigger_mode'] : 'unknown',
                'next_run' => $step === 'natural_run_authorized' ? $set->nextRun($mission, $now) : null,
                'planned_time' => $set->nextRun($mission, $now),
                'selected' => $gate['selected'] ?? null, 'run_allowed' => $gate['run_allowed'] ?? null,
                'pause_intent' => $runtime['pause_intent'] ?? 'UNSET',
                'acceptance_ready' => $gate['acceptance_ready'] ?? null,
                'source_accepted' => $gate['source_accepted'] ?? null,
                'end_to_end_accepted' => $gate['end_to_end_accepted'] ?? null,
                'gate_reason' => $this->safeCode($gate['reason'] ?? null), 'gate_next_step' => $step,
                'receipt_hash' => $receipt === null ? null : $row->terminal_receipt_hash,
            ];
            if ($index === 0) {
                $health['data_freshness'] = ['state' => $state === 'HOLD' && isset($output['gsc']) ? 'HOLD' : ($state === 'READY' && isset($output['gsc']) ? 'READY' : ($stale ? 'STALE' : 'UNAVAILABLE')), 'time' => $observedAt];
            }
            if ($index === 2) {
                foreach (['policy', 'tool', 'schema', 'registry'] as $dimension) {
                    $value = $dimension === 'registry' ? array_intersect_key($output['drift'] ?? [], array_flip(['role', 'binding', 'prompt'])) : [$output['drift'][$dimension] ?? null];
                    $expected = $dimension === 'registry' ? 3 : 1;
                    $health[$dimension.'_drift'] = ['state' => ! $validResult || $observed === null ? 'UNAVAILABLE' : ($stale ? 'STALE' : ($observed->gt($now) ? 'UNAVAILABLE' : (in_array('DRIFT', $value, true) ? 'HOLD' : (count($value) === $expected && count(array_filter($value, static fn ($v) => $v === 'MATCH')) === $expected ? 'READY' : 'UNAVAILABLE')))), 'time' => $observedAt];
                }
            }
        }
        $health['trace_completeness'] = ['state' => $missingReceipts ? 'UNAVAILABLE' : 'READY', 'time' => null];

        return ['public_gate' => $this->safeCode($runtime['public_gate'] ?? null), 'pause_intent' => $this->safeCode($runtime['pause_intent'] ?? null),
            'scheduler_enabled' => (bool) config('seo_council.scheduler_enabled', false),
            'runtime_state' => $this->safeCode($runtime['state'] ?? null), 'enabled' => $runtime['computation_enabled'] ?? false,
            'audit_enabled' => $runtime['audit_enabled'] ?? false, 'business_write_enabled' => false, 'health' => $health,
            'business_hold_count' => $businessHolds, 'evidence_issue_count' => $evidenceIssues,
            'actionable_count' => $businessHolds + $evidenceIssues, 'items' => $items];
    }

    private function latestResult($rows, string $missionId, bool $controlled, CarbonImmutable $now): ?array
    {
        $row = $rows->first(fn ($row) => $row->mission_id === $missionId
            && in_array($row->status, self::TERMINAL, true)
            && str_starts_with($row->slot_key, 'a08:acceptance:') === $controlled);

        return $row === null ? null : app(Platform12MissionEvidenceReadService::class)->summary($row, $this->receipt($row), $now);
    }

    private function safeCode(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $value) === 1 ? $value : 'UNAVAILABLE';
    }

    /** @return list<array{label_key:string,state:string,observed_at:?string,hash:string}> */
    private function sourceChecks(mixed $scheduled, array $output, CarbonImmutable $now): array
    {
        if (! is_array($scheduled)) {
            return [];
        }
        $allowed = ['gsc_scheduled_receipt', 'scheduled_runtime_probe', 'public_api_health',
            'url_truth_reconciliation', 'issue_cluster', 'd1_observation', 'sitemap_observation',
            'private_route_negative_set', 'evidence_expiry', 'registry_version_vector',
            'stored_evidence_safety', 'council_tool_audit'];
        $items = [];
        foreach (array_slice($scheduled['source_refs'] ?? [], 0, 8) as $source) {
            if (! is_array($source) || ! in_array($source['id'] ?? null, $allowed, true)) {
                continue;
            }
            $observed = Platform12OperationsTime::instant($source['observed_at'] ?? null);
            $read = Platform12OperationsTime::instant($source['read_at'] ?? null);
            $observed = $observed !== null && $observed->lte($now) ? $observed : null;
            $read = $read !== null && $read->lte($now) ? $read : null;
            $count = match ($source['id']) {
                'gsc_scheduled_receipt' => data_get($output, 'gsc.row_count'),
                'd1_observation' => data_get($output, 'd1_observation.candidate_denominator'),
                'issue_cluster' => data_get($output, 'clustering_dedupe.issue_denominator'),
                'evidence_expiry' => data_get($output, 'evidence_freshness.total_count'),
                default => null,
            };
            $items[] = ['label_key' => 'seo-council.sources.'.$source['id'], 'state' => $observed === null ? 'UNAVAILABLE' : ($count === 0 ? 'VALID_ZERO' : 'AVAILABLE'),
                'observed_at' => Platform12OperationsTime::iso($observed), 'read_at' => Platform12OperationsTime::iso($read),
                'hash' => preg_match('/^[a-f0-9]{64}$/D', (string) ($source['hash'] ?? '')) === 1 ? $source['hash'] : 'unavailable'];
        }
        foreach (array_slice($scheduled['source_gaps'] ?? [], 0, 8 - count($items)) as $gap) {
            $stale = is_string($gap) && str_ends_with($gap, '_stale');
            $id = $stale ? substr($gap, 0, -6) : $gap;
            if (in_array($id, $allowed, true)) {
                $items[] = ['label_key' => 'seo-council.sources.'.$id, 'state' => $stale ? 'STALE' : 'UNAVAILABLE',
                    'observed_at' => null, 'read_at' => null, 'hash' => 'unavailable'];
            }
        }

        return $items;
    }
}
