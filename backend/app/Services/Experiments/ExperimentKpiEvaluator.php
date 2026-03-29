<?php

declare(strict_types=1);

namespace App\Services\Experiments;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExperimentKpiEvaluator
{
    private const ROLLOUTS_TABLE = 'scoring_model_rollouts';

    private const STAGE_KEYS = [
        'start_test',
        'submit_attempt',
        'checkout_start',
        'payment_succeeded',
        'report_ready',
    ];

    private const METRIC_KEYS = [
        'conversion_rate',
        'paid_order_rate',
        'payment_success_rate',
        'report_ready_rate',
        'submission_failed_rate',
        'start_to_submit_rate',
        'submit_to_checkout_rate',
        'checkout_to_payment_rate',
        'payment_to_report_ready_rate',
    ];

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
     *     metrics:array<string,array{value:float,sample_size:int,source:string}>,
     *     funnel:array{stage_counts:array<string,int>,stage_sources:array<string,string>}
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

        $metricsPayload = $this->buildMetricsForRollout($orgId, $rollout, max(1, $windowMinutes));
        $this->assertMetricSchema($metricsPayload['metrics'] ?? []);

        $result = $this->governanceService->evaluateGuardrails(
            $orgId,
            (string) $rollout->id,
            $actorUserId,
            $metricsPayload['metrics'],
            $reason
        );
        if ($result === null) {
            return null;
        }

        $result['metrics'] = $metricsPayload['metrics'];
        $result['funnel'] = $metricsPayload['funnel'];

        return $result;
    }

    /**
     * @return list<array{
     *     rollout:array<string,mixed>,
     *     guardrails:list<array<string,mixed>>,
     *     rolled_back:bool,
     *     triggered_count:int,
     *     audit_id:?string,
     *     metrics:array<string,array{value:float,sample_size:int,source:string}>,
     *     funnel:array{stage_counts:array<string,int>,stage_sources:array<string,string>}
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
     * @return array{
     *   metrics:array<string,array{value:float,sample_size:int,source:string}>,
     *   funnel:array{stage_counts:array<string,int>,stage_sources:array<string,string>}
     * }
     */
    public function buildMetricsForRollout(int $orgId, object $rollout, int $windowMinutes): array
    {
        $windowStart = now()->subMinutes(max(1, $windowMinutes));
        $attemptRows = $this->collectRolloutAttemptRows($orgId, $rollout, $windowStart);
        $attemptIds = array_keys($attemptRows);

        $submitFallback = [];
        if ($attemptIds !== []) {
            $submitRows = DB::table('events')
                ->where('org_id', $orgId)
                ->where('event_code', 'test_submit')
                ->whereIn('attempt_id', $attemptIds)
                ->where('occurred_at', '>=', $windowStart)
                ->orderBy('occurred_at')
                ->get(['attempt_id', 'occurred_at'])
                ->all();

            foreach ($submitRows as $submitRow) {
                $attemptId = trim((string) ($submitRow->attempt_id ?? ''));
                if ($attemptId === '') {
                    continue;
                }

                $submitAt = $this->resolveTimestamp([$submitRow->occurred_at ?? null]);
                if ($submitAt === null) {
                    continue;
                }

                $submitFallback[$attemptId] = $this->minTimestamp($submitFallback[$attemptId] ?? null, $submitAt);
            }
        }

        $orderStages = $this->collectOrderStageRows($orgId, $attemptIds, $windowStart);
        $reportReadyRows = $this->collectReportReadyRows($orgId, $attemptIds, $windowStart);

        $stageCounts = array_fill_keys(self::STAGE_KEYS, 0);
        foreach ($attemptRows as $attemptId => $attemptRow) {
            $normalizedTimeline = $this->normalizeStageTimeline([
                'start_test_at' => $this->resolveTimestamp([
                    $attemptRow['started_at'] ?? null,
                    $attemptRow['created_at'] ?? null,
                ]),
                'submit_attempt_at' => $this->resolveTimestamp([
                    $attemptRow['submitted_at'] ?? null,
                    $submitFallback[$attemptId] ?? null,
                ]),
                'checkout_start_at' => $this->resolveTimestamp([
                    $orderStages[$attemptId]['checkout_start_at'] ?? null,
                ]),
                'payment_succeeded_at' => $this->resolveTimestamp([
                    $orderStages[$attemptId]['payment_succeeded_at'] ?? null,
                ]),
                'report_ready_at' => $this->resolveTimestamp([
                    $reportReadyRows[$attemptId] ?? null,
                ]),
            ]);

            foreach (self::STAGE_KEYS as $stageKey) {
                if (($normalizedTimeline[$stageKey] ?? null) !== null) {
                    $stageCounts[$stageKey]++;
                }
            }
        }

        $startTestCount = (int) ($stageCounts['start_test'] ?? 0);
        $submitAttemptCount = (int) ($stageCounts['submit_attempt'] ?? 0);
        $checkoutStartCount = (int) ($stageCounts['checkout_start'] ?? 0);
        $paymentSucceededCount = (int) ($stageCounts['payment_succeeded'] ?? 0);
        $reportReadyCount = (int) ($stageCounts['report_ready'] ?? 0);

        $metrics = [
            'conversion_rate' => [
                'value' => $this->safeRate($paymentSucceededCount, $startTestCount),
                'sample_size' => $startTestCount,
                'source' => 'payment_succeeded/start_test',
            ],
            'paid_order_rate' => [
                'value' => $this->safeRate($paymentSucceededCount, $checkoutStartCount),
                'sample_size' => $checkoutStartCount,
                'source' => 'payment_succeeded/checkout_start',
            ],
            'payment_success_rate' => [
                'value' => $this->safeRate($paymentSucceededCount, $checkoutStartCount),
                'sample_size' => $checkoutStartCount,
                'source' => 'payment_succeeded/checkout_start',
            ],
            'report_ready_rate' => [
                'value' => $this->safeRate($reportReadyCount, $paymentSucceededCount),
                'sample_size' => $paymentSucceededCount,
                'source' => 'report_ready/payment_succeeded',
            ],
            'submission_failed_rate' => [
                'value' => $this->safeRate(max(0, $submitAttemptCount - $reportReadyCount), $submitAttemptCount),
                'sample_size' => $submitAttemptCount,
                'source' => '(submit_attempt-report_ready)/submit_attempt',
            ],
            'start_to_submit_rate' => [
                'value' => $this->safeRate($submitAttemptCount, $startTestCount),
                'sample_size' => $startTestCount,
                'source' => 'submit_attempt/start_test',
            ],
            'submit_to_checkout_rate' => [
                'value' => $this->safeRate($checkoutStartCount, $submitAttemptCount),
                'sample_size' => $submitAttemptCount,
                'source' => 'checkout_start/submit_attempt',
            ],
            'checkout_to_payment_rate' => [
                'value' => $this->safeRate($paymentSucceededCount, $checkoutStartCount),
                'sample_size' => $checkoutStartCount,
                'source' => 'payment_succeeded/checkout_start',
            ],
            'payment_to_report_ready_rate' => [
                'value' => $this->safeRate($reportReadyCount, $paymentSucceededCount),
                'sample_size' => $paymentSucceededCount,
                'source' => 'report_ready/payment_succeeded',
            ],
        ];

        $funnel = $this->buildFunnelPayload($stageCounts);

        return [
            'metrics' => $metrics,
            'funnel' => $funnel,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectRolloutAttemptRows(int $orgId, object $rollout, \DateTimeInterface $windowStart): array
    {
        $rows = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where(function ($query) use ($windowStart): void {
                $query->where('started_at', '>=', $windowStart)
                    ->orWhere(function ($fallbackQuery) use ($windowStart): void {
                        $fallbackQuery
                            ->whereNull('started_at')
                            ->where('created_at', '>=', $windowStart);
                    });
            })
            ->get(['id', 'user_id', 'anon_id', 'started_at', 'submitted_at', 'created_at', 'updated_at'])
            ->all();

        if ($rows === []) {
            return [];
        }

        $experimentKey = trim((string) ($rollout->experiment_key ?? ''));
        $experimentVariant = trim((string) ($rollout->experiment_variant ?? ''));
        if ($experimentKey === '') {
            $attemptRows = [];
            foreach ($rows as $row) {
                $attemptId = trim((string) ($row->id ?? ''));
                if ($attemptId === '') {
                    continue;
                }

                $attemptRows[$attemptId] = [
                    'started_at' => $row->started_at ?? null,
                    'submitted_at' => $row->submitted_at ?? null,
                    'created_at' => $row->created_at ?? null,
                    'updated_at' => $row->updated_at ?? null,
                ];
            }

            return $attemptRows;
        }

        $assignmentMap = $this->buildAttemptAssignmentMap($orgId, $experimentKey, $experimentVariant, $rows);
        if ($assignmentMap === []) {
            return [];
        }

        $attemptRows = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $userId = trim((string) ($row->user_id ?? ''));
            $anonId = trim((string) ($row->anon_id ?? ''));
            $matched = false;

            if ($userId !== '' && preg_match('/^\d+$/', $userId) === 1 && isset($assignmentMap['user:'.$userId])) {
                $matched = true;
            }
            if (! $matched && $anonId !== '' && isset($assignmentMap['anon:'.$anonId])) {
                $matched = true;
            }
            if (! $matched) {
                continue;
            }

            $attemptRows[$attemptId] = [
                'started_at' => $row->started_at ?? null,
                'submitted_at' => $row->submitted_at ?? null,
                'created_at' => $row->created_at ?? null,
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return $attemptRows;
    }

    /**
     * @param  list<object>  $attemptRows
     * @return array<string,bool>
     */
    private function buildAttemptAssignmentMap(
        int $orgId,
        string $experimentKey,
        string $experimentVariant,
        array $attemptRows
    ): array {
        $userIds = [];
        $anonIds = [];

        foreach ($attemptRows as $attemptRow) {
            $userId = trim((string) ($attemptRow->user_id ?? ''));
            if ($userId !== '' && preg_match('/^\d+$/', $userId) === 1) {
                $userIds[$userId] = true;
            }

            $anonId = trim((string) ($attemptRow->anon_id ?? ''));
            if ($anonId !== '') {
                $anonIds[$anonId] = true;
            }
        }

        if ($userIds === [] && $anonIds === []) {
            return [];
        }

        $assignmentQuery = DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('experiment_key', $experimentKey)
            ->where(function ($query) use ($userIds, $anonIds): void {
                if ($userIds !== []) {
                    $query->orWhereIn('user_id', array_map('intval', array_keys($userIds)));
                }
                if ($anonIds !== []) {
                    $query->orWhereIn('anon_id', array_keys($anonIds));
                }
            });

        if ($experimentVariant !== '') {
            $assignmentQuery->where('variant', $experimentVariant);
        }

        $assignments = $assignmentQuery
            ->get(['user_id', 'anon_id'])
            ->all();

        $assignmentMap = [];
        foreach ($assignments as $assignment) {
            $userId = trim((string) ($assignment->user_id ?? ''));
            if ($userId !== '' && preg_match('/^\d+$/', $userId) === 1) {
                $assignmentMap['user:'.$userId] = true;
            }

            $anonId = trim((string) ($assignment->anon_id ?? ''));
            if ($anonId !== '') {
                $assignmentMap['anon:'.$anonId] = true;
            }
        }

        return $assignmentMap;
    }

    /**
     * @param  array<int,string>  $attemptIds
     * @return array<string,array{checkout_start_at:?string,payment_succeeded_at:?string}>
     */
    private function collectOrderStageRows(int $orgId, array $attemptIds, \DateTimeInterface $windowStart): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $orderRows = DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('target_attempt_id', $attemptIds)
            ->where(function ($query) use ($windowStart): void {
                $query->where('created_at', '>=', $windowStart)
                    ->orWhere(function ($paidQuery) use ($windowStart): void {
                        $paidQuery->whereNotNull('paid_at')->where('paid_at', '>=', $windowStart);
                    })
                    ->orWhere('updated_at', '>=', $windowStart);
            })
            ->get(['target_attempt_id', 'order_no', 'status', 'created_at', 'paid_at', 'updated_at'])
            ->all();

        $stageRows = [];
        $orderToAttempts = [];

        foreach ($orderRows as $orderRow) {
            $attemptId = trim((string) ($orderRow->target_attempt_id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $checkoutStartAt = $this->resolveTimestamp([$orderRow->created_at ?? null]);
            if ($checkoutStartAt !== null) {
                $stageRows[$attemptId]['checkout_start_at'] = $this->minTimestamp(
                    $stageRows[$attemptId]['checkout_start_at'] ?? null,
                    $checkoutStartAt
                );
            }

            $orderPaymentSuccessAt = null;
            if ($orderRow->paid_at !== null) {
                $orderPaymentSuccessAt = $this->resolveTimestamp([$orderRow->paid_at]);
            } elseif ($this->isPaidLikeStatus((string) ($orderRow->status ?? ''))) {
                $orderPaymentSuccessAt = $this->resolveTimestamp([
                    $orderRow->updated_at ?? null,
                    $orderRow->created_at ?? null,
                ]);
            }
            if ($orderPaymentSuccessAt !== null) {
                $stageRows[$attemptId]['payment_succeeded_at'] = $this->minTimestamp(
                    $stageRows[$attemptId]['payment_succeeded_at'] ?? null,
                    $orderPaymentSuccessAt
                );
            }

            $orderNo = trim((string) ($orderRow->order_no ?? ''));
            if ($orderNo !== '') {
                $orderToAttempts[$orderNo][$attemptId] = true;
            }
        }

        if ($orderToAttempts === []) {
            return $stageRows;
        }

        $paymentRows = DB::table('payment_events')
            ->where('org_id', $orgId)
            ->whereIn('order_no', array_keys($orderToAttempts))
            ->where(function ($query) use ($windowStart): void {
                $query->where('created_at', '>=', $windowStart)
                    ->orWhere(function ($receivedQuery) use ($windowStart): void {
                        $receivedQuery->whereNotNull('received_at')->where('received_at', '>=', $windowStart);
                    })
                    ->orWhere(function ($processedQuery) use ($windowStart): void {
                        $processedQuery->whereNotNull('processed_at')->where('processed_at', '>=', $windowStart);
                    });
            })
            ->get(['order_no', 'status', 'reason', 'processed_at', 'received_at', 'created_at'])
            ->all();

        foreach ($paymentRows as $paymentRow) {
            if (! $this->isPaymentSuccessLike($paymentRow)) {
                continue;
            }

            $orderNo = trim((string) ($paymentRow->order_no ?? ''));
            if ($orderNo === '' || ! isset($orderToAttempts[$orderNo])) {
                continue;
            }

            $paymentSucceededAt = $this->resolveTimestamp([
                $paymentRow->processed_at ?? null,
                $paymentRow->received_at ?? null,
                $paymentRow->created_at ?? null,
            ]);
            if ($paymentSucceededAt === null) {
                continue;
            }

            foreach (array_keys($orderToAttempts[$orderNo]) as $attemptId) {
                $stageRows[$attemptId]['payment_succeeded_at'] = $this->minTimestamp(
                    $stageRows[$attemptId]['payment_succeeded_at'] ?? null,
                    $paymentSucceededAt
                );
            }
        }

        return $stageRows;
    }

    /**
     * @param  array<int,string>  $attemptIds
     * @return array<string,string>
     */
    private function collectReportReadyRows(int $orgId, array $attemptIds, \DateTimeInterface $windowStart): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $rows = DB::table('report_snapshots')
            ->where('org_id', $orgId)
            ->whereIn('attempt_id', $attemptIds)
            ->where(function ($query) use ($windowStart): void {
                $query->where('created_at', '>=', $windowStart)
                    ->orWhere('updated_at', '>=', $windowStart);
            })
            ->get(['attempt_id', 'status', 'updated_at', 'created_at'])
            ->all();

        $readyRows = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            if (! in_array($status, ['ready', 'done', 'generated', 'success', 'completed'], true)) {
                continue;
            }

            $attemptId = trim((string) ($row->attempt_id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $reportReadyAt = $this->resolveTimestamp([
                $row->updated_at ?? null,
                $row->created_at ?? null,
            ]);
            if ($reportReadyAt === null) {
                continue;
            }

            $readyRows[$attemptId] = $this->minTimestamp($readyRows[$attemptId] ?? null, $reportReadyAt);
        }

        return $readyRows;
    }

    /**
     * @param  array{start_test_at:?string,submit_attempt_at:?string,checkout_start_at:?string,payment_succeeded_at:?string,report_ready_at:?string}  $timeline
     * @return array{start_test:?string,submit_attempt:?string,checkout_start:?string,payment_succeeded:?string,report_ready:?string}
     */
    private function normalizeStageTimeline(array $timeline): array
    {
        $startAt = $timeline['start_test_at'] ?? null;
        $submitAt = $timeline['submit_attempt_at'] ?? null;
        $checkoutAt = $timeline['checkout_start_at'] ?? null;
        $paymentAt = $timeline['payment_succeeded_at'] ?? null;
        $reportAt = $timeline['report_ready_at'] ?? null;

        if ($startAt === null) {
            return [
                'start_test' => null,
                'submit_attempt' => null,
                'checkout_start' => null,
                'payment_succeeded' => null,
                'report_ready' => null,
            ];
        }

        if ($submitAt === null || $this->parseTimestamp($submitAt) < $this->parseTimestamp($startAt)) {
            $submitAt = null;
            $checkoutAt = null;
            $paymentAt = null;
            $reportAt = null;
        }

        if (
            $checkoutAt === null
            || $submitAt === null
            || $this->parseTimestamp($checkoutAt) < $this->parseTimestamp($submitAt)
        ) {
            $checkoutAt = null;
            $paymentAt = null;
            $reportAt = null;
        }

        if (
            $paymentAt === null
            || $checkoutAt === null
            || $this->parseTimestamp($paymentAt) < $this->parseTimestamp($checkoutAt)
        ) {
            $paymentAt = null;
            $reportAt = null;
        }

        if (
            $reportAt === null
            || $paymentAt === null
            || $this->parseTimestamp($reportAt) < $this->parseTimestamp($paymentAt)
        ) {
            $reportAt = null;
        }

        return [
            'start_test' => $startAt,
            'submit_attempt' => $submitAt,
            'checkout_start' => $checkoutAt,
            'payment_succeeded' => $paymentAt,
            'report_ready' => $reportAt,
        ];
    }

    /**
     * @param  array<string,int>  $stageCounts
     * @return array{stage_counts:array<string,int>,stage_sources:array<string,string>}
     */
    private function buildFunnelPayload(array $stageCounts): array
    {
        return [
            'stage_counts' => [
                'start_test' => (int) ($stageCounts['start_test'] ?? 0),
                'submit_attempt' => (int) ($stageCounts['submit_attempt'] ?? 0),
                'checkout_start' => (int) ($stageCounts['checkout_start'] ?? 0),
                'payment_succeeded' => (int) ($stageCounts['payment_succeeded'] ?? 0),
                'report_ready' => (int) ($stageCounts['report_ready'] ?? 0),
            ],
            'stage_sources' => [
                'start_test' => 'attempts.started_at|attempts.created_at',
                'submit_attempt' => 'attempts.submitted_at|events(test_submit).occurred_at',
                'checkout_start' => 'orders.created_at by target_attempt_id',
                'payment_succeeded' => 'orders.paid_at|orders.status(updated_at)|payment_events(processed_at/received_at)',
                'report_ready' => 'report_snapshots(status=ready|done|generated|success|completed).updated_at|created_at',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function assertMetricSchema(array $metrics): void
    {
        $actualKeys = array_keys($metrics);
        sort($actualKeys);

        $expectedKeys = self::METRIC_KEYS;
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException('kpi metric schema drift detected: metric keys mismatch.');
        }

        foreach (self::METRIC_KEYS as $metricKey) {
            $metric = $metrics[$metricKey] ?? null;
            if (! is_array($metric)) {
                throw new \RuntimeException('kpi metric schema drift detected: '.$metricKey.' must be object.');
            }

            if (! array_key_exists('value', $metric) || ! is_numeric($metric['value'])) {
                throw new \RuntimeException('kpi metric schema drift detected: '.$metricKey.'.value must be numeric.');
            }

            if (! array_key_exists('sample_size', $metric) || ! is_numeric($metric['sample_size'])) {
                throw new \RuntimeException('kpi metric schema drift detected: '.$metricKey.'.sample_size must be numeric.');
            }

            $sampleSize = (int) $metric['sample_size'];
            if ($sampleSize < 0) {
                throw new \RuntimeException('kpi metric schema drift detected: '.$metricKey.'.sample_size must be >= 0.');
            }

            $source = trim((string) ($metric['source'] ?? ''));
            if ($source === '') {
                throw new \RuntimeException('kpi metric schema drift detected: '.$metricKey.'.source is required.');
            }
        }
    }

    private function isPaidLikeStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['paid', 'fulfilled', 'completed', 'success', 'succeeded'], true);
    }

    private function isPaymentSuccessLike(object $paymentRow): bool
    {
        $status = strtolower(trim((string) ($paymentRow->status ?? '')));
        $reason = strtolower(trim((string) ($paymentRow->reason ?? '')));

        return in_array($status, ['processed', 'handled', 'success', 'succeeded', 'paid', 'completed', 'ok'], true)
            || $paymentRow->processed_at !== null
            || in_array($reason, ['paid', 'success', 'completed'], true);
    }

    private function safeRate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 6);
    }

    private function minTimestamp(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $this->parseTimestamp($left) <= $this->parseTimestamp($right) ? $left : $right;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function resolveTimestamp(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            try {
                return Carbon::parse($candidate)->toIso8601String();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function parseTimestamp(string $timestamp): int
    {
        try {
            return Carbon::parse($timestamp)->getTimestamp();
        } catch (\Throwable) {
            return PHP_INT_MIN;
        }
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
