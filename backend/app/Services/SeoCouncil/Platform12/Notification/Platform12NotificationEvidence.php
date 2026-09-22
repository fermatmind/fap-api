<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\Platform12\Platform12SourceCheck;
use App\Services\SeoIntel\GscRunStartTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only verification of the existing terminal and frozen-source authorities. */
final readonly class Platform12NotificationEvidence
{
    public function __construct(private SeoRegistryHasher $hasher) {}

    public function eventType(array $context): ?string
    {
        $output = $context['output'];
        if (in_array($context['trigger'], ['unknown', 'missed'], true)) {
            return 'DATA_FAILURE';
        }

        return match ($output['state'] ?? null) {
            'WRONG_CANONICAL_HOLD' => 'AUTHORITY_INDEXABILITY_P0',
            'FALSE_NOINDEX_HOLD' => 'AUTHORITY_INDEXABILITY_P1',
            'DENY' => ($output['reason_codes'] ?? []) === ['INPUT_UNAVAILABLE'] ? null : 'PRIVATE_OR_SAFETY',
            'DATA_FRESHNESS_HOLD', 'GSC_UNAVAILABLE_HOLD', 'MAPPING_FAILED_HOLD', 'WINDOW_INCOMPLETE_HOLD', 'DATA_QUALITY_HOLD',
            'RUNTIME_UNAVAILABLE_HOLD', 'RUNTIME_READBACK_HOLD', 'URL_TRUTH_UNAVAILABLE_HOLD', 'CLUSTER_DEDUPE_UNAVAILABLE_HOLD', 'OBSERVATION_UNAVAILABLE_HOLD', 'INPUT_HOLD' => 'DATA_FAILURE',
            'HOLD' => in_array('AUTHORITY_HASH_DRIFT_HOLD', $output['reason_codes'] ?? [], true)
                && in_array('DRIFT', $output['drift'] ?? [], true) ? 'POLICY_HASH_DRIFT'
                    : (array_intersect(['SECURITY_EVIDENCE_UNAVAILABLE', 'INPUT_UNAVAILABLE'], $output['reason_codes'] ?? []) !== [] ? 'DATA_FAILURE' : null),
            default => null,
        };
    }

    public function context(Platform12FrozenMission $mission, array $receipt): ?array
    {
        try {
            $envelope = Platform12FrozenMission::restore($mission->envelope)->envelope;
            $slot = $envelope['slot'];
            $evaluation = collect($receipt['route_plan'] ?? [])->where('kind', 'daily_evaluation');
            $deliveries = collect($receipt['route_plan'] ?? [])->where('kind', 'scheduled_delivery');
            if ($evaluation->count() !== 1 || $deliveries->count() !== 1
                || ($receipt['request_hash'] ?? null) !== $mission->request->requestHash
                || ($receipt['receipt_hash'] ?? null) !== $this->hasher->hashWithout($receipt, 'receipt_hash')) {
                return null;
            }
            $evaluation = $evaluation->first();
            $delivery = $deliveries->first();
            foreach (['mission_id', 'trigger_mode', 'scheduled_for'] as $field) {
                if (($delivery[$field] ?? null) !== ($slot[$field] ?? null)
                    || ($evaluation[$field] ?? null) !== ($slot[$field] ?? null)) {
                    return null;
                }
            }
            $output = $evaluation['output'];
            if (($output['receipt_hash'] ?? null) !== $this->hasher->hashWithout($output, 'receipt_hash')
                || ($output['mission_id'] ?? null) !== $slot['mission_id']
                || ($delivery['source_refs'] ?? null) != $envelope['evidence']['sources']
                || ($evaluation['sources'] ?? null) != $envelope['evidence']['sources']) {
                return null;
            }
            $trigger = $slot['trigger_mode'] ?? '';
            $acceptance = str_starts_with($slot['slot_key'], 'a08:acceptance:');
            $known = in_array($trigger, ['scheduled', 'catch_up', 'controlled_acceptance', 'missed'], true)
                && $acceptance === ($trigger === 'controlled_acceptance');
            $at = CarbonImmutable::parse($envelope['evidence']['captured_at']);

            return ['mission' => $slot['mission_id'], 'trigger' => $known ? $trigger : 'unknown',
                'at' => $at, 'hash' => $receipt['receipt_hash'], 'output' => $output,
                'receipt' => $receipt, 'envelope' => $envelope];
        } catch (Throwable) {
            return null;
        }
    }

    public function terminal(string $hash, bool $terminalTransaction = false): ?array
    {
        try {
            $row = $this->connection()->table('seo_council_schedule_deliveries as d')
                ->join('seo_council_run_receipts as r', 'd.mission_request_hash', '=', 'r.request_hash')
                ->where('r.receipt_hash', $hash)
                ->where(function ($query) use ($hash, $terminalTransaction): void {
                    $query->where('d.terminal_receipt_hash', $hash);
                    if ($terminalTransaction && $this->connection()->transactionLevel() > 0) {
                        $query->orWhere(function ($pending): void {
                            $pending->whereNull('d.terminal_receipt_hash')->whereIn('d.status', ['CLAIMED', 'RECOVERED']);
                        });
                    }
                })
                ->first(['d.mission_request_json', 'd.mission_request_hash', 'd.mission_id', 'd.slot_key', 'r.receipt_json']);
            if ($row === null) {
                return null;
            }
            $mission = Platform12FrozenMission::restore(json_decode($row->mission_request_json, true, 64, JSON_THROW_ON_ERROR));
            if ($row->mission_request_hash !== $mission->request->requestHash
                || $row->mission_id !== $mission->envelope['slot']['mission_id']
                || $row->slot_key !== $mission->envelope['slot']['slot_key']) {
                return null;
            }

            return $this->context($mission, json_decode($row->receipt_json, true, 64, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    public function fromPayload(array $payload, bool $terminalTransaction = false): ?array
    {
        $refs = collect($payload['evidence_refs'] ?? [])->where('id', 'council:daily-terminal');

        return $refs->count() === 1 ? $this->terminal((string) $refs->first()['hash'], $terminalTransaction) : null;
    }

    public function healthy(array $context): bool
    {
        $e = $context['envelope']['evidence'];
        if (! in_array($context['trigger'], ['scheduled', 'catch_up'], true)
            || $context['receipt']['status'] !== 'DAILY_MISSION_READY'
            || $context['output']['state'] !== 'READY' || $e['source_gaps'] !== []
            || ($context['output']['evaluated_at'] ?? null) !== $e['input']['evaluated_at']
            || CarbonImmutable::parse($e['expires_at'])->lte($context['at'])
            || array_diff(Platform12SourceCheck::SOURCES[$context['mission']], array_column($e['sources'], 'id')) !== []) {
            return false;
        }
        foreach ($e['sources'] as $source) {
            if ($this->observedAt($source) === null || $this->observedAt($source)->gt($context['at'])
                || CarbonImmutable::parse($source['read_at'])->ne($context['at'])) {
                return false;
            }
        }

        return true;
    }

    public function resolves(array $healthy, array $failure): bool
    {
        if (! $this->healthy($healthy) || $healthy['mission'] !== $failure['mission']
            || ! $healthy['at']->gt($failure['at'])
            || ! in_array($failure['trigger'], ['scheduled', 'catch_up', 'controlled_acceptance'], true)) {
            return false;
        }
        $sources = match ($failure['output']['state'] ?? '') {
            'DATA_FRESHNESS_HOLD', 'GSC_UNAVAILABLE_HOLD', 'MAPPING_FAILED_HOLD', 'WINDOW_INCOMPLETE_HOLD', 'DATA_QUALITY_HOLD' => ['gsc_scheduled_receipt'],
            'RUNTIME_UNAVAILABLE_HOLD', 'RUNTIME_READBACK_HOLD' => ['scheduled_runtime_probe', 'public_api_health'],
            default => Platform12SourceCheck::SOURCES[$failure['mission']],
        };
        foreach ($sources as $id) {
            $source = collect($healthy['envelope']['evidence']['sources'])->firstWhere('id', $id);
            if ($source === null || $this->observedAt($source) === null || $this->observedAt($source)->lte($failure['at'])) {
                return false;
            }
        }

        return true;
    }

    /** A new full natural health observation must cover the whole affected Mission. */
    public function healthyBetween(array $failure, array $current): bool
    {
        $rows = $this->connection()->table('seo_council_schedule_deliveries')
            ->where('mission_id', $failure['mission'])->where('status', 'CLOSED')
            ->where('scheduled_for', '>', $failure['at']->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<=', $current['at']->format('Y-m-d H:i:s'))
            ->orderByDesc('id')->cursor(['terminal_receipt_hash']);
        foreach ($rows as $row) {
            $healthy = $this->terminal($row->terminal_receipt_hash);
            if ($healthy !== null && $healthy['at']->lte($current['at']) && $this->resolves($healthy, $failure)) {
                return true;
            }
        }

        return false;
    }

    /** No new waiting window: latest successful scheduled refresh, existing 26h
     * contract, exact requested PT date, and no subsequent nightly slot due. */
    public function expectedAcceptanceWait(array $context, bool $historical = false): bool
    {
        if ($context['trigger'] !== 'controlled_acceptance'
            || ($context['output']['state'] ?? null) !== 'DATA_FRESHNESS_HOLD'
            || $context['envelope']['evidence']['source_gaps'] !== []) {
            return false;
        }
        $gsc = $context['output']['gsc'];
        $runtime = $context['output']['runtime'];
        if ($gsc['mapping_state'] !== 'READY' || $gsc['data_quality_state'] !== 'READY'
            || $gsc['window_state'] !== 'COMPLETE' || $runtime['sha_match'] !== true
            || $runtime['core_runtime_state'] !== 'AVAILABLE' || $runtime['public_api_state'] !== 'AVAILABLE'
            || $runtime['readback_state'] !== 'AVAILABLE') {
            return false;
        }
        try {
            $query = $this->connection()->table('seo_gsc_sync_runs')->where('trigger_mode', 'scheduled');
            if ($historical) {
                $start = GscRunStartTime::expression($this->connection());
                $query->where(function ($query) use ($start, $context): void {
                    $query->whereRaw($start.' <= ?', [$context['at']->format('Y-m-d H:i:s')])->orWhereRaw($start.' IS NULL');
                });
            }
            $row = GscRunStartTime::latest($query,
                ['sync_run_uid', 'status', 'finished_at', 'receipt_json'], $context['at']);
            if ($row === null || $row->status !== 'success') {
                return false;
            }
            $r = json_decode($row->receipt_json, true, 64, JSON_THROW_ON_ERROR);
            $finished = CarbonImmutable::parse($row->finished_at, 'UTC');
            $started = CarbonImmutable::parse($row->run_started_at_utc, 'UTC');
            // Nightly's authoritative daily operations slot is 18:17 UTC.
            // The next due slot is a hard boundary, not a scheduler-delay grace.
            $due = $started->setTime(18, 17);
            if ($due->lte($started)) {
                $due = $due->addDay();
            }
            if (($r['schema_version'] ?? null) !== 'seo.gsc_refresh_receipt.v2'
                || ($r['receipt_hash'] ?? null) !== $this->hasher->hashWithout($r, 'receipt_hash')
                || ($r['sync_run_uid'] ?? null) !== $row->sync_run_uid || ($r['status'] ?? null) !== 'success'
                || ($r['trigger_mode'] ?? null) !== 'scheduled' || ($r['reporting_timezone'] ?? null) !== 'America/Los_Angeles'
                || data_get($r, 'quality_gate.status') !== 'pass' || ($r['unmapped_rows'] ?? null) !== 0
                || data_get($r, 'completeness.pagination_complete') !== true || data_get($r, 'completeness.truncated') !== false
                || $started->gt($finished) || $finished->gt($context['at']) || $finished->lt($context['at']->subHours(26))
                || $context['at']->gte($due)
                || ($r['data_max_date'] ?? null) !== $started->setTimezone('America/Los_Angeles')->subDays(3)->toDateString()
                || ($r['end_date'] ?? null) !== $r['data_max_date']) {
                return false;
            }
            $projection = ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => 'success',
                'observed_at' => $finished->format('Y-m-d\TH:i:s\Z'), 'source_hash' => $this->hasher->hash($r),
                'trigger_mode' => 'scheduled', 'mapping_state' => 'READY', 'data_quality_state' => 'READY',
                'window_state' => 'COMPLETE', 'row_count' => $r['rows_seen'], 'data_max_date' => $r['data_max_date']];
            $source = collect($context['envelope']['evidence']['sources'])->firstWhere('id', 'gsc_scheduled_receipt');

            return ($source['hash'] ?? null) === $this->hasher->hash($projection)
                && $context['envelope']['evidence']['input']['gsc'] == array_diff_key($projection, ['observed_at' => true, 'source_hash' => true]);
        } catch (Throwable) {
            return false;
        }
    }

    private function observedAt(array $source): ?CarbonImmutable
    {
        if ($source['observed_at'] !== null) {
            return CarbonImmutable::parse($source['observed_at']);
        }

        // These ProductionEvidenceReader loaders make a live bounded read,
        // unlike persisted GSC/probe/sitemap receipts. Their read instant is
        // the observation instant; never invent one for a missing receipt.
        return in_array($source['id'], ['public_api_health', 'url_truth_reconciliation', 'issue_cluster',
            'd1_observation', 'evidence_expiry', 'registry_version_vector', 'stored_evidence_safety', 'council_tool_audit'], true)
                ? CarbonImmutable::parse($source['read_at']) : null;
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection((string) config('seo_council.connection', 'seo_intel'));
    }
}
