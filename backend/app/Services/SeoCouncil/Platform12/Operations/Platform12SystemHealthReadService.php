<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12SystemHealthReadService
{
    private const ACTIVE_DELIVERY_STATES = ['PLANNED', 'CLAIMED', 'RECOVERED'];

    private const HOLD_DELIVERY_STATES = ['HELD', 'FAILED'];

    public function __construct(
        private Platform12SanitizedOperationsProjector $projector,
        private Platform12IssueExplanation $explanations,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        try {
            $connection = DB::connection((string) config('seo_council.connection', 'seo_intel'));
            $now = CarbonImmutable::now('UTC');
            $activeLeases = $connection->table('seo_council_scheduler_leases')
                ->where('lease_expires_at', '>', $now->format('Y-m-d H:i:s'))
                ->count();
            $deliveryCounts = $this->deliveryCounts($connection);
            $latest = $connection->table('seo_council_schedule_deliveries')
                ->select(['status', 'updated_at'])
                ->orderByDesc('updated_at')
                ->first();
            try {
                $daily = $this->dailyMissions($connection);
            } catch (Throwable) {
                $daily = ['runtime_state' => 'UNAVAILABLE', 'enabled' => false, 'audit_enabled' => false,
                    'business_write_enabled' => false, 'actionable_count' => null, 'items' => []];
            }

            return [...$this->projector->systemHealth([
                'availability' => 'AVAILABLE',
                'freshness' => 'FRESH',
                'records' => $this->records($now, $activeLeases, $deliveryCounts, $latest, $daily),
            ]), 'daily_missions' => $daily];
        } catch (Throwable) {
            return $this->unavailableSnapshot();
        }
    }

    /** @return array<string, mixed> */
    public function unavailableSnapshot(): array
    {
        return $this->projector->systemHealth([
            'availability' => 'UNAVAILABLE',
            'freshness' => 'UNKNOWN',
            'records' => [],
        ]);
    }

    /** @return array<string, int> */
    private function deliveryCounts(ConnectionInterface $connection): array
    {
        $counts = [];
        foreach ($connection->table('seo_council_schedule_deliveries')
            ->selectRaw('status, COUNT(*) AS aggregate_count')
            ->groupBy('status')
            ->get() as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $deliveryCounts
     * @return list<array<string, mixed>>
     */
    private function records(CarbonImmutable $now, int $activeLeases, array $deliveryCounts, ?object $latest, array $daily): array
    {
        $schedulerEnabled = (bool) config('seo_council.scheduler_enabled', false);
        $activeDeliveries = $this->sumStates($deliveryCounts, self::ACTIVE_DELIVERY_STATES);
        $heldDeliveries = isset($daily['health']) ? $daily['actionable_count'] : $this->sumStates($deliveryCounts, self::HOLD_DELIVERY_STATES);
        $latestAt = $latest === null ? null : CarbonImmutable::parse((string) $latest->updated_at, 'UTC');
        $isStale = $latestAt !== null && $latestAt->lt($now->subDay());
        $backlogState = match (true) {
            $heldDeliveries > 0 => 'HOLD',
            $isStale && $activeDeliveries > 0 => 'STALE',
            $activeLeases + $activeDeliveries === 0 => 'VALID_ZERO',
            default => 'READY',
        };
        $control = app(Platform12RuntimeControl::class)->status();
        $runtimeState = ! $schedulerEnabled ? 'DISABLED'
            : ($control['computation_enabled'] ? 'READY' : 'HOLD');
        $freshnessState = match (true) {
            $latestAt === null => 'UNAVAILABLE',
            $isStale => 'STALE',
            default => 'READY',
        };
        $health = $daily['health'] ?? [];
        $observedAt = $latestAt?->format('Y-m-d\TH:i:s\Z');
        $notification = 'DISABLED';
        $notificationFailures = 0;
        if ($control['computation_enabled'] || config('seo_council.notification_dispatch_enabled', false)) {
            try {
                $notificationFailures = DB::connection((string) config('seo_council.connection', 'seo_intel'))
                    ->table('seo_council_notification_outbox')->where('status', 'failed')->count();
                $notification = $notificationFailures > 0 ? 'HOLD' : 'READY';
            } catch (Throwable) {
                $notification = 'UNAVAILABLE';
            }
        }

        return [
            $this->record('scheduler', 'production_council_scheduler_'.strtolower($runtimeState), $runtimeState, 0),
            $this->record('lease_backlog', 'active_lease_and_delivery_backlog', $backlogState, $activeLeases + $activeDeliveries),
            $this->record('data_freshness', 'latest_scheduler_delivery', $health['data_freshness'] ?? $freshnessState, $latestAt === null ? 0 : 1, $observedAt),
            $this->record('policy_drift', 'runtime_policy_vector', $health['policy'] ?? 'UNAVAILABLE', 0, $observedAt),
            $this->record('registry_drift', 'runtime_registry_vector', $health['registry'] ?? 'UNAVAILABLE', 0, $observedAt),
            $this->record('tool_drift', 'runtime_tool_vector', $health['tool'] ?? 'UNAVAILABLE', 0, $observedAt),
            $this->record('schema_drift', 'runtime_schema_vector', $health['schema'] ?? 'UNAVAILABLE', 0, $observedAt),
            $this->record('trace_completeness', 'scheduler_trace_coverage', $health['trace'] ?? 'UNAVAILABLE', 0, $observedAt),
            $this->record('cost', 'model_runtime_cost_events', config('seo_council.model_runtime_enabled') ? 'HOLD' : 'VALID_ZERO', 0),
            $this->record(
                'notification_transport',
                'notification_dispatch_'.($notification === 'DISABLED' ? 'disabled' : 'enabled'),
                $notification,
                $notificationFailures,
            ),
            $this->record('write_guards', 'production_write_guards_closed', $this->writeGuardsClosed() ? 'READY' : 'HOLD', 0),
        ];
    }

    /** @return array{component:string,summary_code:string,state:string,count:int,observed_at?:string} */
    private function record(string $component, string $summaryCode, string $state, int $count, ?string $observedAt = null): array
    {
        $record = compact('component', 'state', 'count');
        $record['summary_code'] = $summaryCode;
        if ($observedAt !== null) {
            $record['observed_at'] = $observedAt;
        }

        return $record;
    }

    /** @param list<string> $states */
    private function sumStates(array $counts, array $states): int
    {
        return array_sum(array_map(static fn (string $state): int => $counts[$state] ?? 0, $states));
    }

    private function writeGuardsClosed(): bool
    {
        return app(Platform12RuntimeControl::class)->businessGuardsClosed();
    }

    private function dailyMissions(ConnectionInterface $connection): array
    {
        $runtime = app(Platform12RuntimeControl::class)->status();
        $latest = $connection->table('seo_council_schedule_deliveries')
            ->selectRaw('MAX(id) AS id')->whereIn('mission_id', Platform12DailyMissionSet::IDS)->groupBy('mission_id');
        $rows = $connection->table('seo_council_schedule_deliveries AS d')
            ->joinSub($latest, 'latest', 'd.id', '=', 'latest.id')
            ->leftJoin('seo_council_run_receipts AS r', 'd.terminal_receipt_reference', '=', 'r.receipt_id')
            ->limit(3)->get(['d.mission_id', 'd.status', 'd.scheduled_for', 'd.updated_at', 'd.terminal_receipt_hash', 'r.receipt_json'])
            ->keyBy('mission_id');
        $set = app(Platform12DailyMissionSet::class);
        $items = [];
        $health = [];
        $verified = 0;
        $terminal = 0;
        foreach ($set->missions() as $index => $mission) {
            $row = $rows->get($mission['mission_id']);
            $receipt = $row !== null && is_string($row->receipt_json) && strlen($row->receipt_json) <= 262144
                ? json_decode($row->receipt_json, true) : null;
            if (is_array($receipt) && (! is_string($row->terminal_receipt_hash)
                || ! hash_equals(app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'), $row->terminal_receipt_hash))) {
                $receipt = null;
            }
            if ($row !== null && in_array($row->status, ['CLOSED', 'HELD', 'FAILED'], true)) {
                $terminal++;
                $verified += (int) is_array($receipt);
            }
            $status = $row === null ? 'NOT_STARTED' : match ($row->status) {
                'CLOSED' => is_array($receipt) ? 'READY' : 'UNAVAILABLE',
                'HELD', 'FAILED' => 'HOLD',
                'PLANNED', 'CLAIMED', 'RECOVERED' => 'RUNNING',
                default => 'UNAVAILABLE',
            };
            $scheduled = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'scheduled_delivery');
            $output = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'daily_evaluation')['output'] ?? [];
            $stale = $row !== null && CarbonImmutable::parse($row->updated_at, 'UTC')->lt(CarbonImmutable::now('UTC')->subHours(26));
            if ($stale && $status === 'READY') {
                $status = 'STALE';
            }
            if ($index === 0) {
                $health['data_freshness'] = $stale ? 'STALE' : (isset($output['gsc']) ? ($output['state'] === 'READY' ? 'READY' : 'HOLD') : 'UNAVAILABLE');
            }
            if ($index === 2) {
                foreach (['policy', 'tool', 'schema'] as $dimension) {
                    $health[$dimension] = $stale ? 'STALE' : match ($output['drift'][$dimension] ?? null) {
                        'MATCH' => 'READY', 'DRIFT' => 'HOLD', default => 'UNAVAILABLE',
                    };
                }
                $states = array_intersect_key($output['drift'] ?? [], array_flip(['role', 'binding', 'prompt']));
                $health['registry'] = $stale ? 'STALE' : (count($states) !== 3 || in_array('UNAVAILABLE', $states, true)
                    ? 'UNAVAILABLE' : (in_array('DRIFT', $states, true) ? 'HOLD' : 'READY'));
            }
            $sourceGaps = $scheduled['source_gaps'] ?? null;
            $explanation = $this->explanations->for($output, $status, is_array($sourceGaps) && $sourceGaps !== []);
            $items[] = [
                'label_key' => 'seo-council.missions.'.$index,
                'state' => $status,
                ...$explanation,
                'source_checks' => $this->sourceChecks($scheduled),
                'observed_at' => $row !== null ? CarbonImmutable::parse($row->updated_at, 'UTC')->toAtomString() : null,
                'next_run' => $set->nextRun($mission, CarbonImmutable::now('UTC')),
                'receipt_hash' => preg_match('/^[a-f0-9]{64}$/D', (string) $row?->terminal_receipt_hash) === 1
                    ? $row->terminal_receipt_hash : null,
            ];
        }

        $health['trace'] = $terminal === 0 ? 'UNAVAILABLE' : ($verified === $terminal ? 'READY' : 'HOLD');

        return ['runtime_state' => $runtime['state'], 'runtime_phase' => $runtime['runtime_phase'],
            'pause_source' => $runtime['pause_source'], 'pause_reason' => $runtime['pause_reason'],
            'changed_at' => $runtime['changed_at'], 'operation_ref' => $runtime['operation_ref'],
            'enabled' => $runtime['computation_enabled'],
            'audit_enabled' => $runtime['audit_enabled'], 'business_write_enabled' => false, 'health' => $health,
            'actionable_count' => count(array_filter($items, static fn (array $item): bool => in_array($item['state'], ['HOLD', 'STALE', 'UNAVAILABLE'], true))),
            'items' => $items];
    }

    /** @return list<array{label_key:string,state:string,observed_at:?string,hash:string}> */
    private function sourceChecks(mixed $scheduled): array
    {
        if (! is_array($scheduled)) {
            return [];
        }
        $allowed = ['gsc_scheduled_receipt', 'gsc_controlled_acceptance_receipt', 'scheduled_runtime_probe', 'public_api_health',
            'url_truth_reconciliation', 'issue_cluster', 'd1_observation', 'sitemap_observation',
            'private_route_negative_set', 'evidence_expiry', 'registry_version_vector',
            'stored_evidence_safety', 'council_tool_audit'];
        $items = [];
        foreach (array_slice($scheduled['source_refs'] ?? [], 0, 8) as $source) {
            if (! is_array($source) || ! in_array($source['id'] ?? null, $allowed, true)) {
                continue;
            }
            $observed = $source['observed_at'] ?? null;
            $items[] = ['label_key' => 'seo-council.sources.'.$source['id'], 'state' => 'AVAILABLE',
                'observed_at' => is_string($observed) ? $observed : null,
                'hash' => preg_match('/^[a-f0-9]{64}$/D', (string) ($source['hash'] ?? '')) === 1 ? $source['hash'] : 'unavailable'];
        }
        foreach (array_slice($scheduled['source_gaps'] ?? [], 0, 8 - count($items)) as $gap) {
            $stale = is_string($gap) && str_ends_with($gap, '_stale');
            $id = $stale ? substr($gap, 0, -6) : $gap;
            if (in_array($id, $allowed, true)) {
                $items[] = ['label_key' => 'seo-council.sources.'.$id, 'state' => $stale ? 'STALE' : 'UNAVAILABLE',
                    'observed_at' => null, 'hash' => 'unavailable'];
            }
        }

        return $items;
    }
}
