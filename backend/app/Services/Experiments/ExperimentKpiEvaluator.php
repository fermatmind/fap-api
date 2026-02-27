<?php

declare(strict_types=1);

namespace App\Services\Experiments;

use Illuminate\Support\Facades\DB;

final class ExperimentKpiEvaluator
{
    private const ROLLOUTS_TABLE = 'scoring_model_rollouts';

    public function __construct(
        private readonly ExperimentGovernanceService $governanceService,
    ) {}

    /**
     * @return array{
     *     rollout:array<string,mixed>,
     *     guardrails:list<array<string,mixed>>,
     *     rolled_back:bool,
     *     triggered_count:int,
     *     audit_id:?string,
     *     metrics:array<string,array{value:float,sample_size:int}>
     * }|null
     */
    public function evaluateRollout(
        int $orgId,
        string $rolloutId,
        ?int $actorUserId,
        int $windowMinutes = 60,
        ?string $reason = null
    ): ?array {
        $rollout = $this->findRollout($orgId, $rolloutId);
        if ($rollout === null) {
            return null;
        }

        $metrics = $this->buildMetricsForRollout($orgId, $rollout, max(1, $windowMinutes));

        $result = $this->governanceService->evaluateGuardrails(
            $orgId,
            (string) $rollout->id,
            $actorUserId,
            $metrics,
            $reason
        );
        if ($result === null) {
            return null;
        }

        $result['metrics'] = $metrics;

        return $result;
    }

    /**
     * @return list<array{
     *     rollout:array<string,mixed>,
     *     guardrails:list<array<string,mixed>>,
     *     rolled_back:bool,
     *     triggered_count:int,
     *     audit_id:?string,
     *     metrics:array<string,array{value:float,sample_size:int}>
     * }>
     */
    public function evaluateActiveRollouts(
        int $orgId,
        ?int $actorUserId,
        int $windowMinutes = 60,
        ?string $reason = null
    ): array {
        if ($orgId <= 0) {
            return [];
        }

        $rollouts = DB::table(self::ROLLOUTS_TABLE)
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get(['id'])
            ->all();

        $results = [];
        foreach ($rollouts as $rollout) {
            $rolloutId = trim((string) ($rollout->id ?? ''));
            if ($rolloutId === '') {
                continue;
            }

            $evaluated = $this->evaluateRollout($orgId, $rolloutId, $actorUserId, $windowMinutes, $reason);
            if ($evaluated !== null) {
                $results[] = $evaluated;
            }
        }

        return $results;
    }

    /**
     * @return array<string,array{value:float,sample_size:int}>
     */
    public function buildMetricsForRollout(int $orgId, object $rollout, int $windowMinutes): array
    {
        $windowStart = now()->subMinutes(max(1, $windowMinutes));
        $exposure = $this->collectExposureAttempts($orgId, $rollout, $windowStart);

        $exposureCount = count($exposure['exposure_keys']);
        $attemptIds = array_keys($exposure['attempt_ids']);

        $orderRows = [];
        if ($attemptIds !== []) {
            $orderRows = DB::table('orders')
                ->where('org_id', $orgId)
                ->whereIn('target_attempt_id', $attemptIds)
                ->where(function ($query) use ($windowStart): void {
                    $query->where('created_at', '>=', $windowStart)
                        ->orWhere(function ($paidQuery) use ($windowStart): void {
                            $paidQuery->whereNotNull('paid_at')->where('paid_at', '>=', $windowStart);
                        });
                })
                ->get(['order_no', 'status', 'paid_at', 'target_attempt_id'])
                ->all();
        }

        $paidAttemptIds = [];
        $paidOrderNos = [];
        $paidOrdersCount = 0;
        foreach ($orderRows as $orderRow) {
            $status = strtolower(trim((string) ($orderRow->status ?? '')));
            $isPaid = in_array($status, ['paid', 'fulfilled', 'completed', 'success', 'succeeded'], true)
                || $orderRow->paid_at !== null;
            if (! $isPaid) {
                continue;
            }

            $paidOrdersCount++;
            $targetAttemptId = trim((string) ($orderRow->target_attempt_id ?? ''));
            if ($targetAttemptId !== '') {
                $paidAttemptIds[$targetAttemptId] = true;
            }

            $orderNo = trim((string) ($orderRow->order_no ?? ''));
            if ($orderNo !== '') {
                $paidOrderNos[$orderNo] = true;
            }
        }

        $paymentRows = [];
        if ($paidOrderNos !== []) {
            $paymentRows = DB::table('payment_events')
                ->where('org_id', $orgId)
                ->whereIn('order_no', array_keys($paidOrderNos))
                ->where(function ($query) use ($windowStart): void {
                    $query->where('created_at', '>=', $windowStart)
                        ->orWhere(function ($receivedQuery) use ($windowStart): void {
                            $receivedQuery->whereNotNull('received_at')->where('received_at', '>=', $windowStart);
                        });
                })
                ->get(['status', 'processed_at', 'reason'])
                ->all();
        }

        $paymentSuccessCount = 0;
        foreach ($paymentRows as $paymentRow) {
            $status = strtolower(trim((string) ($paymentRow->status ?? '')));
            $reason = strtolower(trim((string) ($paymentRow->reason ?? '')));
            $isSuccess = in_array($status, ['processed', 'handled', 'success', 'succeeded', 'paid', 'completed', 'ok'], true)
                || $paymentRow->processed_at !== null
                || in_array($reason, ['paid', 'success', 'completed'], true);
            if ($isSuccess) {
                $paymentSuccessCount++;
            }
        }

        $readyAttempts = [];
        if ($attemptIds !== []) {
            $snapshotRows = DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->whereIn('attempt_id', $attemptIds)
                ->where(function ($query) use ($windowStart): void {
                    $query->where('created_at', '>=', $windowStart)
                        ->orWhere(function ($updatedQuery) use ($windowStart): void {
                            $updatedQuery->whereNotNull('updated_at')->where('updated_at', '>=', $windowStart);
                        });
                })
                ->get(['attempt_id', 'status'])
                ->all();

            foreach ($snapshotRows as $snapshotRow) {
                $attemptId = trim((string) ($snapshotRow->attempt_id ?? ''));
                if ($attemptId === '') {
                    continue;
                }

                $status = strtolower(trim((string) ($snapshotRow->status ?? 'ready')));
                if (in_array($status, ['ready', 'done', 'generated', 'success', 'completed'], true)) {
                    $readyAttempts[$attemptId] = true;
                }
            }
        }

        $conversionRate = $this->safeRate(count($paidAttemptIds), $exposureCount);
        $paidOrderRate = $this->safeRate($paidOrdersCount, count($orderRows));
        $paymentSuccessRate = $this->safeRate($paymentSuccessCount, count($paymentRows));
        $reportReadyRate = $this->safeRate(count($readyAttempts), $exposureCount);
        $submissionFailedRate = $this->safeRate(max(0, $exposureCount - count($readyAttempts)), $exposureCount);

        return [
            'conversion_rate' => [
                'value' => $conversionRate,
                'sample_size' => $exposureCount,
            ],
            'paid_order_rate' => [
                'value' => $paidOrderRate,
                'sample_size' => count($orderRows),
            ],
            'payment_success_rate' => [
                'value' => $paymentSuccessRate,
                'sample_size' => count($paymentRows),
            ],
            'report_ready_rate' => [
                'value' => $reportReadyRate,
                'sample_size' => $exposureCount,
            ],
            'submission_failed_rate' => [
                'value' => $submissionFailedRate,
                'sample_size' => $exposureCount,
            ],
        ];
    }

    /**
     * @return array{attempt_ids:array<string,bool>,exposure_keys:array<string,bool>}
     */
    private function collectExposureAttempts(int $orgId, object $rollout, \DateTimeInterface $windowStart): array
    {
        $rows = DB::table('events')
            ->where('org_id', $orgId)
            ->whereIn('event_code', ['result_view', 'report_view'])
            ->where('occurred_at', '>=', $windowStart)
            ->get(['id', 'attempt_id', 'experiments_json'])
            ->all();

        $attemptIds = [];
        $exposureKeys = [];

        foreach ($rows as $row) {
            $experiments = $this->decodeJsonRecord($row->experiments_json ?? null);
            if (! $this->matchesRolloutExperiment($rollout, $experiments)) {
                continue;
            }

            $attemptId = trim((string) ($row->attempt_id ?? ''));
            if ($attemptId !== '') {
                $attemptIds[$attemptId] = true;
                $exposureKeys['attempt:'.$attemptId] = true;
                continue;
            }

            $eventId = trim((string) ($row->id ?? ''));
            if ($eventId !== '') {
                $exposureKeys['event:'.$eventId] = true;
            }
        }

        return [
            'attempt_ids' => $attemptIds,
            'exposure_keys' => $exposureKeys,
        ];
    }

    /**
     * @param  array<string,mixed>  $experiments
     */
    private function matchesRolloutExperiment(object $rollout, array $experiments): bool
    {
        $experimentKey = trim((string) ($rollout->experiment_key ?? ''));
        if ($experimentKey === '') {
            return true;
        }

        $variant = trim((string) ($rollout->experiment_variant ?? ''));
        $assigned = trim((string) ($experiments[$experimentKey] ?? ''));
        if ($assigned === '') {
            return false;
        }

        if ($variant === '') {
            return true;
        }

        return $assigned === $variant;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonRecord(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function safeRate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 6);
    }

    private function findRollout(int $orgId, string $rolloutId): ?object
    {
        $normalizedRolloutId = trim($rolloutId);
        if ($orgId <= 0 || $normalizedRolloutId === '') {
            return null;
        }

        return DB::table(self::ROLLOUTS_TABLE)
            ->where('org_id', $orgId)
            ->where('id', $normalizedRolloutId)
            ->first();
    }
}
