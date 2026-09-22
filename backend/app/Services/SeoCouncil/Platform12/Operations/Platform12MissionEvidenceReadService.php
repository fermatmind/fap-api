<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationEvidence;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Display only the frozen sources belonging to this terminal result. */
final readonly class Platform12MissionEvidenceReadService
{
    public function summary(object $row, ?array $receipt, CarbonImmutable $now): ?array
    {
        try {
            if ($receipt === null || ! is_string($row->mission_request_json) || strlen($row->mission_request_json) > 131072) {
                return null;
            }
            $mission = Platform12FrozenMission::restore(json_decode($row->mission_request_json, true, 64, JSON_THROW_ON_ERROR));
            $slot = $mission->envelope['slot'];
            if ($row->mission_request_hash !== $mission->request->requestHash
                || $row->mission_id !== $slot['mission_id'] || $row->slot_key !== $slot['slot_key']) {
                return null;
            }
            $context = app(Platform12NotificationEvidence::class)->context($mission, $receipt);
            $output = $context['output'] ?? null;
            $evaluated = Platform12OperationsTime::instant($output['evaluated_at'] ?? null);
            if ($context === null || $context['trigger'] === 'unknown' || $evaluated === null || $evaluated->gt($now)
                || ($output['evaluated_at'] ?? null) !== data_get($mission->envelope, 'evidence.input.evaluated_at')) {
                return null;
            }
            $gsc = $output['gsc'] ?? [];
            $runtime = $output['runtime'] ?? [];
            $runtimeStates = [];
            foreach (['core_runtime_state', 'public_api_state', 'readback_state'] as $dimension) {
                $runtimeStates[$dimension] = $this->code($runtime[$dimension] ?? null);
            }
            $sources = [];
            foreach ($mission->envelope['evidence']['sources'] as $source) {
                $observed = Platform12OperationsTime::instant($source['observed_at']);
                $read = Platform12OperationsTime::instant($source['read_at']);
                $sources[] = ['id' => $source['id'], 'hash' => $source['hash'],
                    'observed_at' => $observed !== null && $observed->lte($evaluated) ? Platform12OperationsTime::iso($observed) : null,
                    'read_at' => $read !== null && $read->lte($evaluated) ? Platform12OperationsTime::iso($read) : null];
            }

            return ['origin' => $context['trigger'], 'state' => $this->code($output['state']),
                'is_gsc' => $slot['mission_id'] === 'seo.platform12.daily_gsc_core_runtime',
                'evaluated_at' => Platform12OperationsTime::iso($evaluated),
                'receipt_hash' => $receipt['receipt_hash'], 'sources' => $sources,
                'data_max_date' => $this->date($gsc['data_max_date'] ?? null),
                'lag_days' => is_int($gsc['lag_days'] ?? null) ? $gsc['lag_days'] : null,
                'runtime' => $runtimeStates,
                'production_sha' => $this->sha($runtime['production_sha'] ?? null),
                'readback_sha' => $this->sha($runtime['readback_sha'] ?? null),
                'gsc_collection' => $this->gscCollection($mission->envelope, $evaluated)];
        } catch (Throwable) {
            return null;
        }
    }

    private function gscCollection(array $envelope, CarbonImmutable $evaluated): ?array
    {
        try {
            $source = collect($envelope['evidence']['sources'])->firstWhere('id', 'gsc_scheduled_receipt');
            $observed = Platform12OperationsTime::instant($source['observed_at'] ?? null);
            $input = $envelope['evidence']['input']['gsc'] ?? null;
            if ($observed === null || $observed->gt($evaluated) || ! is_array($input)) {
                return null;
            }
            // Select the historical observation, never the current latest run.
            $rows = DB::connection((string) config('seo_intel.connection', 'seo_intel'))->table('seo_gsc_sync_runs')
                ->where('trigger_mode', 'scheduled')->where('finished_at', $observed->format('Y-m-d H:i:s'))
                ->select(['status', 'sync_run_uid'])
                ->selectRaw('CASE WHEN LENGTH(receipt_json) <= 262144 THEN receipt_json ELSE NULL END AS receipt_json')
                ->limit(3)->get();
            if ($rows->count() > 2) {
                return null;
            }
            $matches = [];
            $hasher = app(SeoRegistryHasher::class);
            foreach ($rows as $row) {
                $r = is_string($row->receipt_json) ? json_decode($row->receipt_json, true, 32, JSON_THROW_ON_ERROR) : null;
                if (! is_array($r) || ($r['schema_version'] ?? null) !== 'seo.gsc_refresh_receipt.v2'
                    || ($r['sync_run_uid'] ?? null) !== $row->sync_run_uid || ($r['trigger_mode'] ?? null) !== 'scheduled'
                    || ($r['reporting_timezone'] ?? null) !== 'America/Los_Angeles') {
                    continue;
                }
                $projection = ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => $row->status,
                    'observed_at' => $observed->format('Y-m-d\TH:i:s\Z'), 'source_hash' => $hasher->hash($r),
                    'trigger_mode' => $r['trigger_mode'],
                    'mapping_state' => ($r['unmapped_rows'] ?? null) === 0 ? 'READY' : 'FAILED',
                    'data_quality_state' => $row->status === 'success' && data_get($r, 'quality_gate.status') === 'pass' ? 'READY' : 'HOLD',
                    'window_state' => $row->status === 'success' && $observed->gte($evaluated->subHours(26)) ? 'COMPLETE' : 'INCOMPLETE',
                    'row_count' => $r['rows_seen'] ?? null, 'data_max_date' => $r['data_max_date'] ?? null];
                if ($hasher->hash($projection) !== $source['hash']
                    || array_diff_key($projection, ['observed_at' => true, 'source_hash' => true]) != $input) {
                    continue;
                }
                $start = $this->date($r['start_date'] ?? null);
                $end = $this->date($r['end_date'] ?? null);
                if ($start === null || $end === null || $start > $end) {
                    continue;
                }
                $matches[] = ['start_date' => $start, 'end_date' => $end, 'cutoff_date' => $end,
                    'requested_start_date' => $this->date($r['requested_start_date'] ?? null),
                    'timezone' => 'America/Los_Angeles', 'source_hash' => $source['hash']];
            }

            return count($matches) === 1 ? $matches[0] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            && Platform12OperationsTime::instant($value.'T00:00:00Z') !== null ? $value : null;
    }

    private function sha(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) ? $value : null;
    }

    private function code(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $value) ? $value : 'UNAVAILABLE';
    }
}
