<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Persistence\CouncilRunRepository;
use App\Services\SeoCouncil\Platform12\Notification\Platform12DailyNotifications;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12DailyScheduler
{
    private const LEASE = 'platform12:daily:serial';

    public function __construct(
        private Platform12RuntimeControl $control,
        private Platform12DailyMissionSet $missions,
        private Platform12ContractRegistry $contracts,
        private Platform12SchedulerStore $store,
        private Platform12EvidenceReader $evidence,
        private RuntimeCapabilitySnapshotBuilder $capabilities,
        private ScheduledMissionAdapter $adapter,
        private SeoCouncilOrchestrator $orchestrator,
        private CouncilRunRepository $runs,
        private SeoRegistryHasher $hasher,
        private Platform12DailyNotifications $notifications,
    ) {}

    public function tick(?string $acceptanceMission = null, ?string $acceptanceOperation = null): array
    {
        if ($acceptanceMission !== null && (! in_array($acceptanceMission, Platform12DailyMissionSet::IDS, true)
            || $acceptanceOperation === null)) {
            return $this->result('ACCEPTANCE_SCOPE_DENIED');
        }
        $state = $this->control->status();
        if ($acceptanceMission !== null && (! ($state['controlled_acceptance_enabled'] ?? false)
            || ! hash_equals((string) ($state['operation_ref'] ?? ''), $acceptanceOperation))) {
            return $this->result('ACCEPTANCE_AUTHORITY_DENIED');
        }
        if ($acceptanceMission === null && ! $state['computation_enabled']) {
            return $this->result($state['state']);
        }
        $owner = bin2hex(random_bytes(24));
        $lease = $this->store->acquire(self::LEASE, $owner, 180);
        if (! $lease['acquired']) {
            return $this->result($lease['status']);
        }
        $fence = $lease['fencing_token'];
        $start = hrtime(true);
        try {
            // One outbox claim per tick; successful checks create no notification.
            $this->notifications->drain();
            $catalog = $this->contracts->missionCatalog();
            $vector = $this->capabilities->snapshot()['version_vector'];
            $row = $this->deliveries()->whereIn('mission_id', Platform12DailyMissionSet::IDS)
                ->whereNotIn('status', ['CLOSED', 'HELD', 'FAILED'])
                ->orderBy('scheduled_for')->first();
            if ($row !== null && $acceptanceMission !== null
                && ! $this->sameAcceptanceDelivery($row, $acceptanceMission)) {
                return $this->result('ACCEPTANCE_REQUIRES_IDLE');
            }
            if ($row === null) {
                $slot = $acceptanceMission === null ? $this->nextSlot($state) : [
                    'mission_id' => $acceptanceMission,
                    'slot_key' => 'a08:acceptance:'.array_search($acceptanceMission, Platform12DailyMissionSet::IDS, true)
                        .':'.CarbonImmutable::now('Asia/Shanghai')->toDateString().':'.$this->releaseSha(),
                    'scheduled_for' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'trigger_mode' => 'controlled_acceptance',
                ];
                if ($slot === null) {
                    return $this->result('IDLE');
                }
                $existing = $this->deliveries()->where('slot_key', $slot['slot_key'])->first();
                if ($existing !== null) {
                    return $this->existingAcceptance($existing);
                }
                $evidence = $slot['trigger_mode'] === 'missed'
                    ? ['input' => [], 'sources' => [], 'source_gaps' => ['missed_slot'],
                        'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                        'expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z')]
                    : $this->evidence->capture($slot['mission_id']);
                if (! $this->sameGeneration($state, $acceptanceMission !== null)) {
                    return $this->result('PAUSED_BEFORE_RESERVATION');
                }
                $mission = Platform12FrozenMission::freeze($slot, $evidence, $vector, $catalog['catalog_hash']);
                $id = $this->hasher->hash([$catalog['catalog_hash'], $slot['slot_key']]);
                $reserved = $this->store->reserveDelivery([
                    'delivery_id' => $id, 'slot_key' => $slot['slot_key'], 'scheduled_for' => $slot['scheduled_for'],
                    'catalog_version' => $catalog['catalog_version'], 'catalog_hash' => $catalog['catalog_hash'],
                    'mission_id' => $slot['mission_id'], 'mission_request_hash' => $mission->request->requestHash,
                    'mission_request' => $mission->envelope, 'idempotency_key' => $mission->request->idempotencyKey(),
                ], $this->storeVector($mission));
                if (! $reserved['accepted']) {
                    return $this->result($reserved['status']);
                }
                $row = $this->deliveries()->where('delivery_id', $id)->first();
            }
            $mission = Platform12FrozenMission::restore(json_decode($row->mission_request_json, true, 64, JSON_THROW_ON_ERROR));
            if ($row->mission_request_hash !== $mission->request->requestHash
                || $row->idempotency_key !== $mission->request->idempotencyKey()
                || $row->mission_id !== $mission->envelope['slot']['mission_id']
                || $row->slot_key !== $mission->envelope['slot']['slot_key']
                || $row->catalog_hash !== $mission->envelope['catalog_hash']) {
                return $this->result('FROZEN_DELIVERY_INTEGRITY_HOLD');
            }
            $storeVector = $this->storeVector($mission);
            $claimed = $row->status === 'PLANNED'
                ? $this->store->claimDelivery($row->delivery_id, self::LEASE, $owner, $fence, $storeVector)
                : $this->store->recoverStaleDelivery($row->delivery_id, self::LEASE, $owner, $fence, $storeVector);
            if (! $claimed['accepted']) {
                return $this->result($claimed['status']);
            }
            $reason = null;
            if ($claimed['status'] === 'RECOVERY_EXHAUSTED_HOLD') {
                $reason = 'daily_recovery_exhausted';
            } elseif ($mission->envelope['catalog_hash'] !== $catalog['catalog_hash']
                || $mission->envelope['version_vector'] !== $vector) {
                $reason = 'daily_version_drift';
            } elseif ($mission->envelope['slot']['trigger_mode'] === 'missed'
                || CarbonImmutable::parse($row->scheduled_for, 'UTC')->setTimezone('Asia/Shanghai')->toDateString()
                    !== CarbonImmutable::now('Asia/Shanghai')->toDateString()) {
                $reason = 'daily_missed_slot';
            } elseif (! $this->sameGeneration($state, $acceptanceMission !== null)) {
                $reason = 'daily_paused';
            } elseif ((hrtime(true) - $start) / 1e9 >= 120) {
                $reason = 'daily_timeout';
            }
            $receipt = $reason !== null ? $this->orchestrator->stoppedScheduled($mission, $reason)
                : $this->adapter->submitFrozen($mission);
            if (! $this->sameGeneration($state, $acceptanceMission !== null) || (hrtime(true) - $start) / 1e9 >= 120) {
                $receipt = $this->orchestrator->stoppedScheduled($mission, 'daily_paused_or_timeout');
            }
            $receipt['route_plan'][] = [
                'kind' => 'scheduled_delivery',
                'mission_id' => $mission->envelope['slot']['mission_id'],
                'scheduled_for' => $mission->envelope['slot']['scheduled_for'],
                'trigger_mode' => $mission->envelope['slot']['trigger_mode'],
                'source_gaps' => $mission->envelope['evidence']['source_gaps'],
                'source_refs' => $mission->envelope['evidence']['sources'],
                'elapsed_ms' => (int) ((hrtime(true) - $start) / 1e6),
            ];
            $receipt['receipt_hash'] = $this->hasher->hashWithout($receipt, 'receipt_hash');
            $result = $this->store->completeDelivery($row->delivery_id, self::LEASE, $owner, $fence,
                $receipt['receipt_id'], $receipt['receipt_hash'], $receipt['status'] === 'DAILY_MISSION_READY' ? 'CLOSED' : 'HELD',
                function ($connection) use ($mission, $receipt, $state, $vector, $acceptanceMission): void {
                    if ($connection->getName() !== config('seo_council.connection', 'seo_intel')
                        || ($receipt['status'] !== 'DAILY_STOPPED_HOLD' && (! $this->sameGeneration($state, $acceptanceMission !== null)
                            || $vector !== $this->capabilities->snapshot()['version_vector']))) {
                        throw new \RuntimeException('TERMINAL_RUNTIME_OR_VERSION_HOLD');
                    }
                    $persisted = $this->runs->persist($receipt, $mission->request->idempotencyKey(), $mission);
                    if (! in_array($persisted['decision'], ['PERSISTED', 'REPLAY'], true)
                        || $persisted['receipt']['receipt_hash'] !== $receipt['receipt_hash']) {
                        throw new \RuntimeException('TERMINAL_AUDIT_PERSISTENCE_HOLD');
                    }
                    $this->notifications->enqueue($mission, $receipt);
                    if ($receipt['status'] !== 'DAILY_STOPPED_HOLD' && (! $this->sameGeneration($state, $acceptanceMission !== null)
                        || $vector !== $this->capabilities->snapshot()['version_vector'])) {
                        throw new \RuntimeException('TERMINAL_RUNTIME_CHANGED');
                    }
                }, $storeVector);

            $evaluation = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'daily_evaluation')['output'] ?? [];

            return $this->result($result['status'], ['mission_id' => $row->mission_id,
                'terminal_committed' => $result['terminal_committed'] ?? false,
                'mission_verdict' => is_array($evaluation) ? ($evaluation['state'] ?? null) : null,
                'source_gaps' => $mission->envelope['evidence']['source_gaps'],
                'receipt_hash' => ($result['terminal_committed'] ?? false) ? $receipt['receipt_hash'] : null]);
        } catch (Throwable) {
            return $this->result('DAILY_RUNTIME_HOLD');
        } finally {
            $this->store->release(self::LEASE, $owner, $fence);
        }
    }

    private function nextSlot(array $state): ?array
    {
        $activated = CarbonImmutable::parse($state['activated_at']);
        $now = CarbonImmutable::now('UTC');
        $last = $this->deliveries()->whereIn('mission_id', Platform12DailyMissionSet::IDS)
            ->where('slot_key', 'not like', 'a08:acceptance:%')->max('scheduled_for');
        $date = $last === null ? $activated : CarbonImmutable::parse($last, 'UTC');
        // Bounded lookback: one date per tick, with missed slots retained rather than replayed.
        foreach ([$date, $date->addDay()] as $day) {
            foreach ($this->missions->slots($day, $activated, $now) as $slot) {
                if (! $this->deliveries()->where('slot_key', $slot['slot_key'])->exists()) {
                    return $slot;
                }
            }
        }

        return null;
    }

    private function sameGeneration(array $started, bool $acceptance): bool
    {
        $current = $this->control->status();

        return ($acceptance ? ($current['controlled_acceptance_enabled'] ?? false) : $current['computation_enabled'])
            && $current['generation'] === $started['generation'];
    }

    private function sameAcceptanceDelivery(object $delivery, string $missionId): bool
    {
        $index = array_search($missionId, Platform12DailyMissionSet::IDS, true);
        $prefix = 'a08:acceptance:'.$index.':';

        return $delivery->mission_id === $missionId
            && str_starts_with((string) $delivery->slot_key, $prefix)
            && str_ends_with((string) $delivery->slot_key, ':'.$this->releaseSha());
    }

    private function existingAcceptance(object $delivery): array
    {
        if (! in_array((string) $delivery->status, ['CLOSED', 'HELD'], true)
            || ! is_string($delivery->terminal_receipt_hash)
            || preg_match('/^[a-f0-9]{64}$/D', $delivery->terminal_receipt_hash) !== 1) {
            return $this->result('ACCEPTANCE_ALREADY_RECORDED');
        }
        $envelope = json_decode((string) $delivery->mission_request_json, true, 64, JSON_THROW_ON_ERROR);

        return $this->result('TERMINAL_REPLAY', [
            'mission_id' => $delivery->mission_id,
            'terminal_committed' => true,
            'mission_verdict' => (string) $delivery->status === 'CLOSED' ? 'READY' : 'HOLD',
            'source_gaps' => data_get($envelope, 'evidence.source_gaps', []),
            'receipt_hash' => $delivery->terminal_receipt_hash,
        ]);
    }

    private function releaseSha(): string
    {
        $path = (string) config('seo_council.release_revision_path', dirname(base_path()).'/REVISION');
        $sha = is_file($path) ? strtolower(trim((string) file_get_contents($path))) : '';
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            if (app()->environment('testing')) {
                return str_repeat('0', 12);
            }
            throw new \RuntimeException('RELEASE_REVISION_HOLD');
        }

        return substr($sha, 0, 12);
    }

    private function storeVector(Platform12FrozenMission $mission): array
    {
        $vector = $mission->envelope['version_vector'];

        return ['catalog_hash' => $mission->envelope['catalog_hash'], 'policy_hash' => $vector['policy'],
            'role_hash' => $vector['role'], 'tool_hash' => $vector['tool'], 'schema_hash' => $vector['schema'],
            'evidence_hash' => $this->hasher->hash([$vector, $mission->request->evidenceHash])];
    }

    private function deliveries(): \Illuminate\Database\Query\Builder
    {
        return DB::connection((string) config('seo_council.connection', 'seo_intel'))->table('seo_council_schedule_deliveries');
    }

    private function result(string $status, array $extra = []): array
    {
        return ['status' => $status, ...$extra, 'execution_allowed' => false, 'business_write_enabled' => false];
    }
}
