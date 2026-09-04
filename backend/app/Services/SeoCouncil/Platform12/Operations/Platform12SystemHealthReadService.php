<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12SystemHealthReadService
{
    private const ACTIVE_DELIVERY_STATES = ['PLANNED', 'CLAIMED'];

    private const HOLD_DELIVERY_STATES = ['HELD', 'FAILED'];

    public function __construct(private Platform12SanitizedOperationsProjector $projector) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        try {
            $connection = DB::connection('seo_intel');
            $now = CarbonImmutable::now('UTC');
            $activeLeases = $connection->table('seo_council_scheduler_leases')
                ->where('lease_expires_at', '>', $now->format('Y-m-d H:i:s'))
                ->count();
            $deliveryCounts = $this->deliveryCounts($connection);
            $latest = $connection->table('seo_council_schedule_deliveries')
                ->select(['status', 'updated_at'])
                ->orderByDesc('updated_at')
                ->first();

            return $this->projector->systemHealth([
                'availability' => 'AVAILABLE',
                'freshness' => 'FRESH',
                'records' => $this->records($now, $activeLeases, $deliveryCounts, $latest),
            ]);
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
    private function records(CarbonImmutable $now, int $activeLeases, array $deliveryCounts, ?object $latest): array
    {
        $schedulerEnabled = (bool) config('seo_council.scheduler_enabled', false);
        $activeDeliveries = $this->sumStates($deliveryCounts, self::ACTIVE_DELIVERY_STATES);
        $heldDeliveries = $this->sumStates($deliveryCounts, self::HOLD_DELIVERY_STATES);
        $latestAt = $latest === null ? null : CarbonImmutable::parse((string) $latest->updated_at, 'UTC');
        $isStale = $latestAt !== null && $latestAt->lt($now->subDay());
        $backlogState = match (true) {
            $heldDeliveries > 0 => 'HOLD',
            $isStale && $activeDeliveries > 0 => 'STALE',
            $activeLeases + $activeDeliveries === 0 => 'VALID_ZERO',
            default => 'READY',
        };
        $runtimeState = $schedulerEnabled ? 'HOLD' : 'DISABLED';
        $freshnessState = match (true) {
            $latestAt === null => 'UNAVAILABLE',
            $isStale => 'STALE',
            default => 'READY',
        };
        $runtimeVectorState = $schedulerEnabled && $latestAt !== null ? 'HOLD' : 'UNAVAILABLE';
        $observedAt = $latestAt?->format('Y-m-d\TH:i:s\Z');

        return [
            $this->record('scheduler', 'production_council_scheduler_'.strtolower($runtimeState), $runtimeState, 0),
            $this->record('lease_backlog', 'active_lease_and_delivery_backlog', $backlogState, $activeLeases + $activeDeliveries),
            $this->record('data_freshness', 'latest_scheduler_delivery', $freshnessState, $latestAt === null ? 0 : 1, $observedAt),
            $this->record('policy_drift', 'runtime_policy_vector', $runtimeVectorState, 0, $observedAt),
            $this->record('registry_drift', 'runtime_registry_vector', $runtimeVectorState, 0, $observedAt),
            $this->record('tool_drift', 'runtime_tool_vector', $runtimeVectorState, 0, $observedAt),
            $this->record('schema_drift', 'runtime_schema_vector', $runtimeVectorState, 0, $observedAt),
            $this->record('trace_completeness', 'scheduler_trace_coverage', $latestAt === null ? 'UNAVAILABLE' : 'HOLD', 0, $observedAt),
            $this->record('cost', 'model_runtime_cost_events', $schedulerEnabled ? 'HOLD' : 'VALID_ZERO', 0),
            $this->record(
                'notification_transport',
                'notification_dispatch_'.((bool) config('seo_council.notification_dispatch_enabled', false) ? 'enabled' : 'disabled'),
                (bool) config('seo_council.notification_dispatch_enabled', false) ? 'HOLD' : 'DISABLED',
                0,
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
        return ! (bool) config('seo_council.scheduler_enabled', false)
            && ! (bool) config('seo_council.mission_execution_enabled', false)
            && ! (bool) config('seo_council.model_runtime_enabled', false)
            && ! (bool) config('seo_council.tool_broker_enabled', false)
            && ! (bool) config('seo_council.notification_dispatch_enabled', false);
    }
}
