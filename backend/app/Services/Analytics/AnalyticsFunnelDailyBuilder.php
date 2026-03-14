<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class AnalyticsFunnelDailyBuilder
{
    private const FIRST_VIEW_EVENT_ALIASES = [
        'result_view',
        'report_view',
        'report_viewed',
        'clinical_combo_68_report_viewed',
        'sds_20_report_viewed',
    ];

    private const PDF_EVENT_ALIASES = [
        'report_pdf_view',
    ];

    private const SHARE_CLICK_EVENT_ALIASES = [
        'share_click',
    ];

    private const SUBMISSION_SUCCESS_STATES = [
        'succeeded',
        'success',
        'ready',
        'completed',
    ];

    private const READY_SNAPSHOT_STATUSES = [
        'ready',
        'full',
        'completed',
        'complete',
        'done',
        'generated',
        'success',
        'succeeded',
    ];

    private const PAYMENT_SUCCESS_EVENT_TYPES = [
        'payment_succeeded',
        'payment_success',
        'subscription_payment_success',
        'invoice.payment_succeeded',
    ];

    private const PAYMENT_SUCCESS_STATUSES = [
        'paid',
        'fulfilled',
        'completed',
        'complete',
        'success',
        'succeeded',
    ];

    private const PAYMENT_SUCCESS_HANDLE_STATUSES = [
        'processed',
        'handled',
        'success',
        'succeeded',
        'ok',
        'reprocessed',
    ];

    private const PAYMENT_SUCCESS_REASONS = [
        'paid',
        'completed',
        'success',
        'succeeded',
        'payment_succeeded',
        'settled',
    ];

    private const METRIC_COLUMN_MAP = [
        'test_start' => 'started_attempts',
        'test_submit_success' => 'submitted_attempts',
        'first_result_or_report_view' => 'first_view_attempts',
        'order_created' => 'order_created_attempts',
        'payment_success' => 'paid_attempts',
        'unlock_success' => 'unlocked_attempts',
        'report_ready' => 'report_ready_attempts',
        'pdf_download' => 'pdf_download_attempts',
        'share_generate' => 'share_generated_attempts',
        'share_click' => 'share_click_attempts',
    ];

    private const PRIMARY_STAGE_KEYS = [
        'test_start',
        'test_submit_success',
        'first_result_or_report_view',
        'order_created',
        'payment_success',
        'unlock_success',
        'report_ready',
    ];

    private const NON_PAYMENT_EVENT_TYPES = [
        'order_created',
        'checkout_start',
        'checkout_started',
        'payment_failed',
        'refund',
        'refund_succeeded',
        'refund_failed',
    ];

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array{
     *     rows:list<array<string,mixed>>,
     *     attempted_rows:int,
     *     org_scope:list<int>,
     *     scale_scope:list<string>,
     *     from:string,
     *     to:string
     * }
     */
    public function build(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
    ): array {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $normalizedScaleCodes = $this->normalizeScaleCodes($scaleCodes);

        $stageMaps = [
            'test_start' => $this->collectTestStartMap($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes),
            'test_submit_success' => $this->collectSubmitSuccessMap($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes),
            'first_result_or_report_view' => $this->collectFirstViewMap($fromAt, $toAt, $normalizedOrgIds),
            'order_created' => $this->collectOrderCreatedMap($fromAt, $toAt, $normalizedOrgIds),
            'payment_success' => $this->collectPaymentSuccessMap($fromAt, $toAt, $normalizedOrgIds),
            'unlock_success' => $this->collectUnlockSuccessMap($fromAt, $toAt, $normalizedOrgIds),
            'report_ready' => $this->collectReportReadyMap($fromAt, $toAt, $normalizedOrgIds),
            'pdf_download' => $this->collectPdfDownloadMap($fromAt, $toAt, $normalizedOrgIds),
            'share_generate' => $this->collectShareGenerateMap($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes),
            'share_click' => $this->collectShareClickMap($fromAt, $toAt, $normalizedOrgIds),
        ];
        $stageMaps = $this->normalizePrimaryStageMaps($stageMaps);

        $revenueEntries = $this->collectPaidRevenueEntries($fromAt, $toAt, $normalizedOrgIds);
        $candidateAttemptIds = $this->candidateAttemptIds($stageMaps, $revenueEntries);
        $dimensions = $this->loadAttemptDimensions($candidateAttemptIds, $normalizedOrgIds, $normalizedScaleCodes);

        $rows = $this->aggregateRows($stageMaps, $revenueEntries, $dimensions);

        return [
            'rows' => array_values($rows),
            'attempted_rows' => count($rows),
            'org_scope' => $normalizedOrgIds,
            'scale_scope' => $normalizedScaleCodes,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array{
     *     rows:list<array<string,mixed>>,
     *     attempted_rows:int,
     *     deleted_rows:int,
     *     upserted_rows:int,
     *     org_scope:list<int>,
     *     scale_scope:list<string>,
     *     from:string,
     *     to:string,
     *     dry_run:bool
     * }
     */
    public function refresh(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
        bool $dryRun = false,
    ): array {
        $payload = $this->build($from, $to, $orgIds, $scaleCodes);
        $rows = $payload['rows'];
        $deletedRows = 0;
        $upsertedRows = 0;

        if (! $dryRun) {
            DB::transaction(function () use ($payload, $rows, &$deletedRows, &$upsertedRows): void {
                $deletedRows = $this->deleteScope(
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['scale_scope']
                );

                if ($rows === []) {
                    return;
                }

                DB::table('analytics_funnel_daily')->upsert(
                    $rows,
                    ['day', 'org_id', 'scale_code', 'locale'],
                    [
                        'started_attempts',
                        'submitted_attempts',
                        'first_view_attempts',
                        'order_created_attempts',
                        'paid_attempts',
                        'paid_revenue_cents',
                        'unlocked_attempts',
                        'report_ready_attempts',
                        'pdf_download_attempts',
                        'share_generated_attempts',
                        'share_click_attempts',
                        'last_refreshed_at',
                        'updated_at',
                    ]
                );

                $upsertedRows = count($rows);
            });
        }

        return $payload + [
            'deleted_rows' => $deletedRows,
            'upserted_rows' => $upsertedRows,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  array<string,array<string,string>>  $stageMaps
     * @param  list<array{attempt_id:string,paid_at:string,amount_cents:int}>  $revenueEntries
     * @return list<string>
     */
    private function candidateAttemptIds(array $stageMaps, array $revenueEntries): array
    {
        $attemptIds = [];

        foreach ($stageMaps as $stageMap) {
            foreach (array_keys($stageMap) as $attemptId) {
                $attemptIds[$attemptId] = true;
            }
        }

        foreach ($revenueEntries as $revenueEntry) {
            $attemptId = trim((string) ($revenueEntry['attempt_id'] ?? ''));
            if ($attemptId !== '') {
                $attemptIds[$attemptId] = true;
            }
        }

        return array_keys($attemptIds);
    }

    /**
     * @param  list<string>  $attemptIds
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array<string,array{org_id:int,scale_code:string,locale:string}>
     */
    private function loadAttemptDimensions(array $attemptIds, array $orgIds, array $scaleCodes): array
    {
        if ($attemptIds === [] || ! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $dimensions = [];

        foreach (array_chunk($attemptIds, 500) as $attemptChunk) {
            $query = DB::table('attempts')
                ->whereIn('id', $attemptChunk)
                ->select('id', 'org_id', 'scale_code', 'locale');

            if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
                $query->addSelect('scale_code_v2');
            }

            if ($orgIds !== []) {
                $query->whereIn('org_id', $orgIds);
            }

            $this->applyAttemptScaleFilter($query, $scaleCodes);

            foreach ($query->get() as $row) {
                $attemptId = trim((string) ($row->id ?? ''));
                if ($attemptId === '') {
                    continue;
                }

                $dimensions[$attemptId] = [
                    'org_id' => max(0, (int) ($row->org_id ?? 0)),
                    'scale_code' => $this->normalizeScaleCodeValue(
                        $row->scale_code_v2 ?? $row->scale_code ?? null
                    ),
                    'locale' => $this->normalizeLocaleValue($row->locale ?? null),
                ];
            }
        }

        return $dimensions;
    }

    /**
     * @param  array<string,array<string,string>>  $stageMaps
     * @param  list<array{attempt_id:string,paid_at:string,amount_cents:int}>  $revenueEntries
     * @param  array<string,array{org_id:int,scale_code:string,locale:string}>  $dimensions
     * @return array<string,array<string,mixed>>
     */
    private function aggregateRows(array $stageMaps, array $revenueEntries, array $dimensions): array
    {
        $rows = [];
        $refreshedAt = now();

        foreach ($stageMaps as $stageKey => $stageMap) {
            $metricColumn = self::METRIC_COLUMN_MAP[$stageKey] ?? null;
            if ($metricColumn === null) {
                continue;
            }

            foreach ($stageMap as $attemptId => $stageAt) {
                if (! isset($dimensions[$attemptId])) {
                    continue;
                }

                $dimension = $dimensions[$attemptId];
                $day = CarbonImmutable::parse($stageAt)->toDateString();
                $rowKey = $this->aggregateKey($day, $dimension['org_id'], $dimension['scale_code'], $dimension['locale']);

                if (! isset($rows[$rowKey])) {
                    $rows[$rowKey] = $this->emptyAggregateRow($day, $dimension, $refreshedAt);
                }

                $rows[$rowKey][$metricColumn]++;
            }
        }

        foreach ($revenueEntries as $revenueEntry) {
            $attemptId = trim((string) ($revenueEntry['attempt_id'] ?? ''));
            if ($attemptId === '' || ! isset($dimensions[$attemptId])) {
                continue;
            }

            $dimension = $dimensions[$attemptId];
            $day = CarbonImmutable::parse($revenueEntry['paid_at'])->toDateString();
            $rowKey = $this->aggregateKey($day, $dimension['org_id'], $dimension['scale_code'], $dimension['locale']);

            if (! isset($rows[$rowKey])) {
                $rows[$rowKey] = $this->emptyAggregateRow($day, $dimension, $refreshedAt);
            }

            $rows[$rowKey]['paid_revenue_cents'] += max(0, (int) ($revenueEntry['amount_cents'] ?? 0));
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @param  array{org_id:int,scale_code:string,locale:string}  $dimension
     * @return array<string,mixed>
     */
    private function emptyAggregateRow(string $day, array $dimension, \DateTimeInterface $refreshedAt): array
    {
        return [
            'day' => $day,
            'org_id' => $dimension['org_id'],
            'scale_code' => $dimension['scale_code'],
            'locale' => $dimension['locale'],
            'started_attempts' => 0,
            'submitted_attempts' => 0,
            'first_view_attempts' => 0,
            'order_created_attempts' => 0,
            'paid_attempts' => 0,
            'paid_revenue_cents' => 0,
            'unlocked_attempts' => 0,
            'report_ready_attempts' => 0,
            'pdf_download_attempts' => 0,
            'share_generated_attempts' => 0,
            'share_click_attempts' => 0,
            'last_refreshed_at' => $refreshedAt,
            'created_at' => $refreshedAt,
            'updated_at' => $refreshedAt,
        ];
    }

    private function aggregateKey(string $day, int $orgId, string $scaleCode, string $locale): string
    {
        return implode('|', [$day, (string) $orgId, $scaleCode, $locale]);
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array<string,string>
     */
    private function collectTestStartMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds, array $scaleCodes): array
    {
        if (! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $query = DB::table('attempts')
            ->whereNotNull('id')
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            ->select('id as attempt_id', 'created_at as stage_at');

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $this->applyAttemptScaleFilter($query, $scaleCodes);

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array<string,string>
     */
    private function collectSubmitSuccessMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds, array $scaleCodes): array
    {
        if (! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $submittedMap = [];

        $submittedQuery = DB::table('attempts')
            ->whereNotNull('id')
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from, $to])
            ->select('id as attempt_id', 'submitted_at as stage_at');

        if ($orgIds !== []) {
            $submittedQuery->whereIn('org_id', $orgIds);
        }

        $this->applyAttemptScaleFilter($submittedQuery, $scaleCodes);
        $submittedMap += $this->rowsToAttemptMap($submittedQuery->get()->all(), 'attempt_id', 'stage_at');

        if (SchemaBaseline::hasTable('attempt_submissions')) {
            [$stateSql, $stateBindings] = $this->lowerInSql('state', self::SUBMISSION_SUCCESS_STATES);
            $submissionsSub = DB::table('attempt_submissions')
                ->select('attempt_id')
                ->selectRaw('MIN(COALESCE(finished_at, updated_at, created_at)) as stage_at')
                ->whereRaw($stateSql, $stateBindings)
                ->groupBy('attempt_id');

            $fallbackQuery = DB::table('attempts')
                ->joinSub($submissionsSub, 'submission_stage', function ($join): void {
                    $join->on('submission_stage.attempt_id', '=', 'attempts.id');
                })
                ->whereNull('attempts.submitted_at')
                ->whereBetween('submission_stage.stage_at', [$from, $to])
                ->select('attempts.id as attempt_id', 'submission_stage.stage_at');

            if ($orgIds !== []) {
                $fallbackQuery->whereIn('attempts.org_id', $orgIds);
            }

            $this->applyAttemptScaleFilter($fallbackQuery, $scaleCodes);
            $submittedMap += $this->rowsToAttemptMap($fallbackQuery->get()->all(), 'attempt_id', 'stage_at');
        }

        if (SchemaBaseline::hasTable('results')) {
            $resultsSub = DB::table('results')
                ->select('attempt_id')
                ->selectRaw('MIN(COALESCE(computed_at, created_at)) as stage_at')
                ->groupBy('attempt_id');

            $fallbackQuery = DB::table('attempts')
                ->joinSub($resultsSub, 'result_stage', function ($join): void {
                    $join->on('result_stage.attempt_id', '=', 'attempts.id');
                })
                ->whereNull('attempts.submitted_at');

            if (SchemaBaseline::hasTable('attempt_submissions')) {
                [$stateSql, $stateBindings] = $this->lowerInSql('state', self::SUBMISSION_SUCCESS_STATES);
                $fallbackQuery->leftJoinSub(
                    DB::table('attempt_submissions')
                        ->select('attempt_id')
                        ->selectRaw('MIN(COALESCE(finished_at, updated_at, created_at)) as stage_at')
                        ->whereRaw($stateSql, $stateBindings)
                        ->groupBy('attempt_id'),
                    'submission_stage',
                    function ($join): void {
                        $join->on('submission_stage.attempt_id', '=', 'attempts.id');
                    }
                )->whereNull('submission_stage.stage_at');
            }

            $fallbackQuery
                ->whereBetween('result_stage.stage_at', [$from, $to])
                ->select('attempts.id as attempt_id', 'result_stage.stage_at');

            if ($orgIds !== []) {
                $fallbackQuery->whereIn('attempts.org_id', $orgIds);
            }

            $this->applyAttemptScaleFilter($fallbackQuery, $scaleCodes);

            foreach ($fallbackQuery->get() as $row) {
                $attemptId = trim((string) ($row->attempt_id ?? ''));
                $stageAt = trim((string) ($row->stage_at ?? ''));
                if ($attemptId === '' || $stageAt === '' || isset($submittedMap[$attemptId])) {
                    continue;
                }

                $submittedMap[$attemptId] = $stageAt;
            }
        }

        ksort($submittedMap);

        return $submittedMap;
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectFirstViewMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $query = DB::table('events')
            ->whereNotNull('attempt_id')
            ->where('attempt_id', '!=', '')
            ->select('attempt_id')
            ->selectRaw('MIN(occurred_at) as stage_at')
            ->groupBy('attempt_id');

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $this->applyEventAliasFilter($query, self::FIRST_VIEW_EVENT_ALIASES);
        $query->havingRaw('MIN(occurred_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectOrderCreatedMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('orders')) {
            return [];
        }

        $query = DB::table('orders')
            ->whereNotNull('target_attempt_id')
            ->where('target_attempt_id', '!=', '')
            ->select('target_attempt_id as attempt_id')
            ->selectRaw('MIN(created_at) as stage_at')
            ->groupBy('target_attempt_id');

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $query->havingRaw('MIN(created_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectPaymentSuccessMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('orders')) {
            return [];
        }

        $paidMap = [];

        $paidQuery = DB::table('orders')
            ->whereNotNull('target_attempt_id')
            ->where('target_attempt_id', '!=', '')
            ->whereNotNull('paid_at')
            ->select('target_attempt_id as attempt_id')
            ->selectRaw('MIN(paid_at) as stage_at')
            ->groupBy('target_attempt_id')
            ->havingRaw('MIN(paid_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if ($orgIds !== []) {
            $paidQuery->whereIn('org_id', $orgIds);
        }

        $paidMap += $this->rowsToAttemptMap($paidQuery->get()->all(), 'attempt_id', 'stage_at');

        if (! SchemaBaseline::hasTable('payment_events')) {
            return $paidMap;
        }

        $fallbackQuery = DB::table('payment_events')
            ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
            ->whereNotNull('orders.target_attempt_id')
            ->where('orders.target_attempt_id', '!=', '')
            ->whereNull('orders.paid_at')
            ->where(function (QueryBuilder $query): void {
                $query->whereNotNull('payment_events.handled_at')
                    ->orWhereNotNull('payment_events.processed_at');
            })
            ->select('orders.target_attempt_id as attempt_id')
            ->selectRaw('MIN(COALESCE(payment_events.handled_at, payment_events.processed_at)) as stage_at')
            ->groupBy('orders.target_attempt_id');

        if ($orgIds !== []) {
            $fallbackQuery->whereIn('orders.org_id', $orgIds);
        }

        $this->applyPaymentSuccessSignalFilter($fallbackQuery);
        $fallbackQuery->havingRaw(
            'MIN(COALESCE(payment_events.handled_at, payment_events.processed_at)) between ? and ?',
            [$from->toDateTimeString(), $to->toDateTimeString()]
        );

        foreach ($fallbackQuery->get() as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $stageAt = trim((string) ($row->stage_at ?? ''));

            if ($attemptId === '' || $stageAt === '' || isset($paidMap[$attemptId])) {
                continue;
            }

            $paidMap[$attemptId] = $stageAt;
        }

        ksort($paidMap);

        return $paidMap;
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectUnlockSuccessMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('benefit_grants')) {
            return [];
        }

        $query = DB::table('benefit_grants')
            ->whereRaw("lower(coalesce(benefit_grants.status, '')) = ?", ['active'])
            ->selectRaw(
                SchemaBaseline::hasTable('orders')
                    ? 'COALESCE(benefit_grants.attempt_id, orders.target_attempt_id) as attempt_id'
                    : 'benefit_grants.attempt_id as attempt_id'
            )
            ->selectRaw('MIN(benefit_grants.created_at) as stage_at')
            ->havingRaw('MIN(benefit_grants.created_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (SchemaBaseline::hasTable('orders')) {
            $query->leftJoin('orders', function ($join): void {
                $join->on('orders.id', '=', 'benefit_grants.source_order_id');

                if (SchemaBaseline::hasColumn('benefit_grants', 'order_no')) {
                    $join->orOn('orders.order_no', '=', 'benefit_grants.order_no');
                }
            })->groupByRaw('COALESCE(benefit_grants.attempt_id, orders.target_attempt_id)')
                ->havingRaw('COALESCE(benefit_grants.attempt_id, orders.target_attempt_id) is not null');
        } else {
            $query->groupBy('benefit_grants.attempt_id')
                ->whereNotNull('benefit_grants.attempt_id')
                ->where('benefit_grants.attempt_id', '!=', '');
        }

        if ($orgIds !== []) {
            $query->whereIn('benefit_grants.org_id', $orgIds);
        }

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectReportReadyMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('report_snapshots')) {
            return [];
        }

        $query = DB::table('report_snapshots')
            ->whereNotNull('attempt_id')
            ->where('attempt_id', '!=', '')
            ->where(function (QueryBuilder $query) use ($from, $to): void {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('updated_at', [$from, $to]);
            });

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $columns = ['attempt_id', 'status', 'created_at', 'updated_at'];

        foreach (['report_json', 'report_free_json', 'report_full_json'] as $column) {
            if (SchemaBaseline::hasColumn('report_snapshots', $column)) {
                $columns[] = $column;
            }
        }

        $map = [];

        foreach ($query->get($columns) as $row) {
            if (! $this->isReadySnapshot($row)) {
                continue;
            }

            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $stageAt = $this->resolveTimestamp([
                $row->updated_at ?? null,
                $row->created_at ?? null,
            ]);

            if ($attemptId === '' || $stageAt === null) {
                continue;
            }

            $map[$attemptId] = $this->minTimestamp($map[$attemptId] ?? null, $stageAt);
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectPdfDownloadMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $query = DB::table('events')
            ->whereNotNull('attempt_id')
            ->where('attempt_id', '!=', '')
            ->select('attempt_id')
            ->selectRaw('MIN(occurred_at) as stage_at')
            ->groupBy('attempt_id');

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $this->applyEventAliasFilter($query, self::PDF_EVENT_ALIASES);
        $query->havingRaw('MIN(occurred_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array<string,string>
     */
    private function collectShareGenerateMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds, array $scaleCodes): array
    {
        if (! SchemaBaseline::hasTable('shares') || ! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $query = DB::table('shares')
            ->join('attempts', 'attempts.id', '=', 'shares.attempt_id')
            ->whereNotNull('shares.attempt_id')
            ->where('shares.attempt_id', '!=', '')
            ->select('shares.attempt_id')
            ->selectRaw('COALESCE(shares.created_at, shares.updated_at) as stage_at')
            ->whereBetween(DB::raw('COALESCE(shares.created_at, shares.updated_at)'), [$from->toDateTimeString(), $to->toDateTimeString()]);

        if ($orgIds !== []) {
            $query->whereIn('attempts.org_id', $orgIds);
        }

        $this->applyAttemptScaleFilter($query, $scaleCodes, 'attempts');

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @return array<string,string>
     */
    private function collectShareClickMap(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $attemptExpression = SchemaBaseline::hasTable('shares')
            ? 'COALESCE(events.attempt_id, shares.attempt_id)'
            : 'events.attempt_id';

        $query = DB::table('events')
            ->selectRaw($attemptExpression.' as attempt_id')
            ->selectRaw('MIN(events.occurred_at) as stage_at')
            ->groupByRaw($attemptExpression)
            ->havingRaw($attemptExpression.' is not null')
            ->havingRaw('MIN(events.occurred_at) between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (SchemaBaseline::hasTable('shares')) {
            $query->leftJoin('shares', 'shares.id', '=', 'events.share_id');
        }

        if ($orgIds !== []) {
            $query->whereIn('events.org_id', $orgIds);
        }

        $this->applyEventAliasFilter($query, self::SHARE_CLICK_EVENT_ALIASES, 'events');

        return $this->rowsToAttemptMap($query->get()->all(), 'attempt_id', 'stage_at');
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<array{attempt_id:string,paid_at:string,amount_cents:int}>
     */
    private function collectPaidRevenueEntries(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('orders')) {
            return [];
        }

        $entries = [];

        $paidOrdersQuery = DB::table('orders')
            ->whereNotNull('target_attempt_id')
            ->where('target_attempt_id', '!=', '')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->select('target_attempt_id as attempt_id', 'paid_at', 'amount_cents');

        if ($orgIds !== []) {
            $paidOrdersQuery->whereIn('org_id', $orgIds);
        }

        foreach ($paidOrdersQuery->get() as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $paidAt = trim((string) ($row->paid_at ?? ''));
            if ($attemptId === '' || $paidAt === '') {
                continue;
            }

            $entries[] = [
                'attempt_id' => $attemptId,
                'paid_at' => $paidAt,
                'amount_cents' => max(0, (int) ($row->amount_cents ?? 0)),
            ];
        }

        if (! SchemaBaseline::hasTable('payment_events')) {
            return $entries;
        }

        $fallbackQuery = DB::table('payment_events')
            ->join('orders', 'orders.order_no', '=', 'payment_events.order_no')
            ->whereNotNull('orders.target_attempt_id')
            ->where('orders.target_attempt_id', '!=', '')
            ->whereNull('orders.paid_at')
            ->where(function (QueryBuilder $query): void {
                $query->whereNotNull('payment_events.handled_at')
                    ->orWhereNotNull('payment_events.processed_at');
            })
            ->select('orders.order_no', 'orders.target_attempt_id as attempt_id', 'orders.amount_cents')
            ->selectRaw('MIN(COALESCE(payment_events.handled_at, payment_events.processed_at)) as paid_at')
            ->groupBy('orders.order_no', 'orders.target_attempt_id', 'orders.amount_cents')
            ->havingRaw(
                'MIN(COALESCE(payment_events.handled_at, payment_events.processed_at)) between ? and ?',
                [$from->toDateTimeString(), $to->toDateTimeString()]
            );

        if ($orgIds !== []) {
            $fallbackQuery->whereIn('orders.org_id', $orgIds);
        }

        $this->applyPaymentSuccessSignalFilter($fallbackQuery);

        foreach ($fallbackQuery->get() as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $paidAt = trim((string) ($row->paid_at ?? ''));

            if ($attemptId === '' || $paidAt === '') {
                continue;
            }

            $entries[] = [
                'attempt_id' => $attemptId,
                'paid_at' => $paidAt,
                'amount_cents' => max(0, (int) ($row->amount_cents ?? 0)),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string,string>
     */
    private function rowsToAttemptMap(array $rows, string $attemptField, string $stageField): array
    {
        $map = [];

        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->{$attemptField} ?? ''));
            $stageAt = trim((string) ($row->{$stageField} ?? ''));

            if ($attemptId === '' || $stageAt === '') {
                continue;
            }

            $map[$attemptId] = $this->minTimestamp($map[$attemptId] ?? null, $stageAt);
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     */
    private function deleteScope(string $fromDate, string $toDate, array $orgIds, array $scaleCodes): int
    {
        $query = DB::table('analytics_funnel_daily')
            ->whereBetween('day', [$fromDate, $toDate]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        if ($scaleCodes !== []) {
            $query->whereIn('scale_code', $scaleCodes);
        }

        return $query->delete();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            $orgIds
        ), static fn (int $value): bool => $value >= 0)));
    }

    /**
     * @param  list<string>  $scaleCodes
     * @return list<string>
     */
    private function normalizeScaleCodes(array $scaleCodes): array
    {
        $normalized = array_map(
            fn (mixed $value): string => $this->normalizeScaleCodeValue($value),
            $scaleCodes
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== '' && $value !== 'unknown'
        )));
    }

    private function normalizeScaleCodeValue(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function normalizeLocaleValue(mixed $value): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @param  list<string>  $scaleCodes
     */
    private function applyAttemptScaleFilter(QueryBuilder $query, array $scaleCodes, string $table = 'attempts'): void
    {
        if ($scaleCodes === []) {
            return;
        }

        $query->where(function (QueryBuilder $builder) use ($scaleCodes, $table): void {
            if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
                $builder->whereIn($table.'.scale_code_v2', $scaleCodes)
                    ->orWhereIn($table.'.scale_code', $scaleCodes);

                return;
            }

            $builder->whereIn($table.'.scale_code', $scaleCodes);
        });
    }

    /**
     * @param  array<string,array<string,string>>  $stageMaps
     * @return array<string,array<string,string>>
     */
    private function normalizePrimaryStageMaps(array $stageMaps): array
    {
        $attemptIds = [];

        foreach (self::PRIMARY_STAGE_KEYS as $stageKey) {
            foreach (array_keys($stageMaps[$stageKey] ?? []) as $attemptId) {
                $attemptIds[$attemptId] = true;
            }
        }

        foreach (array_keys($attemptIds) as $attemptId) {
            $timeline = $this->normalizePrimaryStageTimeline([
                'test_start' => $stageMaps['test_start'][$attemptId] ?? null,
                'test_submit_success' => $stageMaps['test_submit_success'][$attemptId] ?? null,
                'first_result_or_report_view' => $stageMaps['first_result_or_report_view'][$attemptId] ?? null,
                'order_created' => $stageMaps['order_created'][$attemptId] ?? null,
                'payment_success' => $stageMaps['payment_success'][$attemptId] ?? null,
                'unlock_success' => $stageMaps['unlock_success'][$attemptId] ?? null,
                'report_ready' => $stageMaps['report_ready'][$attemptId] ?? null,
            ]);

            foreach (self::PRIMARY_STAGE_KEYS as $stageKey) {
                if ($timeline[$stageKey] === null) {
                    unset($stageMaps[$stageKey][$attemptId]);

                    continue;
                }

                $stageMaps[$stageKey][$attemptId] = $timeline[$stageKey];
            }
        }

        return $stageMaps;
    }

    /**
     * @param  array{
     *     test_start:?string,
     *     test_submit_success:?string,
     *     first_result_or_report_view:?string,
     *     order_created:?string,
     *     payment_success:?string,
     *     unlock_success:?string,
     *     report_ready:?string
     * }  $timeline
     * @return array{
     *     test_start:?string,
     *     test_submit_success:?string,
     *     first_result_or_report_view:?string,
     *     order_created:?string,
     *     payment_success:?string,
     *     unlock_success:?string,
     *     report_ready:?string
     * }
     */
    private function normalizePrimaryStageTimeline(array $timeline): array
    {
        $startAt = $timeline['test_start'] ?? null;
        $submitAt = $timeline['test_submit_success'] ?? null;
        $viewAt = $timeline['first_result_or_report_view'] ?? null;
        $orderAt = $timeline['order_created'] ?? null;
        $paymentAt = $timeline['payment_success'] ?? null;
        $unlockAt = $timeline['unlock_success'] ?? null;
        $readyAt = $timeline['report_ready'] ?? null;

        if ($startAt === null) {
            return array_fill_keys(self::PRIMARY_STAGE_KEYS, null);
        }

        if ($submitAt === null || $this->timestampBefore($submitAt, $startAt)) {
            $submitAt = null;
            $viewAt = null;
            $orderAt = null;
            $paymentAt = null;
            $unlockAt = null;
            $readyAt = null;
        }

        if ($viewAt === null || $submitAt === null || $this->timestampBefore($viewAt, $submitAt)) {
            $viewAt = null;
            $orderAt = null;
            $paymentAt = null;
            $unlockAt = null;
            $readyAt = null;
        }

        if ($orderAt === null || $viewAt === null || $this->timestampBefore($orderAt, $viewAt)) {
            $orderAt = null;
            $paymentAt = null;
            $unlockAt = null;
            $readyAt = null;
        }

        if ($paymentAt === null || $orderAt === null || $this->timestampBefore($paymentAt, $orderAt)) {
            $paymentAt = null;
            $unlockAt = null;
            $readyAt = null;
        }

        if ($unlockAt === null || $paymentAt === null || $this->timestampBefore($unlockAt, $paymentAt)) {
            $unlockAt = null;
            $readyAt = null;
        }

        if ($readyAt === null || $unlockAt === null || $this->timestampBefore($readyAt, $unlockAt)) {
            $readyAt = null;
        }

        return [
            'test_start' => $startAt,
            'test_submit_success' => $submitAt,
            'first_result_or_report_view' => $viewAt,
            'order_created' => $orderAt,
            'payment_success' => $paymentAt,
            'unlock_success' => $unlockAt,
            'report_ready' => $readyAt,
        ];
    }

    private function timestampBefore(string $left, string $right): bool
    {
        return CarbonImmutable::parse($left)->lessThan(CarbonImmutable::parse($right));
    }

    /**
     * @param  list<string>  $eventAliases
     */
    private function applyEventAliasFilter(QueryBuilder $query, array $eventAliases, string $table = 'events'): void
    {
        [$codeSql, $codeBindings] = $this->lowerInSql($table.'.event_code', $eventAliases);
        [$nameSql, $nameBindings] = $this->lowerInSql($table.'.event_name', $eventAliases);

        $query->where(function (QueryBuilder $builder) use ($codeSql, $codeBindings, $nameSql, $nameBindings): void {
            $builder->whereRaw($codeSql, $codeBindings)
                ->orWhereRaw($nameSql, $nameBindings);
        });
    }

    private function applyPaymentSuccessSignalFilter(QueryBuilder $query): void
    {
        [$typeSql, $typeBindings] = $this->lowerInSql('payment_events.event_type', self::PAYMENT_SUCCESS_EVENT_TYPES);
        [$statusSql, $statusBindings] = $this->lowerInSql('payment_events.status', self::PAYMENT_SUCCESS_STATUSES);
        [$handleSql, $handleBindings] = $this->lowerInSql('payment_events.handle_status', self::PAYMENT_SUCCESS_HANDLE_STATUSES);
        [$reasonSql, $reasonBindings] = $this->lowerInSql('payment_events.reason', self::PAYMENT_SUCCESS_REASONS);
        [$excludeTypeSql, $excludeTypeBindings] = $this->lowerNotInSql('payment_events.event_type', self::NON_PAYMENT_EVENT_TYPES);

        $query->where(function (QueryBuilder $builder) use (
            $typeSql,
            $typeBindings,
            $statusSql,
            $statusBindings,
            $handleSql,
            $handleBindings,
            $reasonSql,
            $reasonBindings,
            $excludeTypeSql,
            $excludeTypeBindings
        ): void {
            $builder->whereRaw($typeSql, $typeBindings)
                ->orWhere(function (QueryBuilder $fallback) use (
                    $statusSql,
                    $statusBindings,
                    $handleSql,
                    $handleBindings,
                    $reasonSql,
                    $reasonBindings,
                    $excludeTypeSql,
                    $excludeTypeBindings
                ): void {
                    $fallback->whereRaw($excludeTypeSql, $excludeTypeBindings)
                        ->where(function (QueryBuilder $signals) use (
                            $statusSql,
                            $statusBindings,
                            $handleSql,
                            $handleBindings,
                            $reasonSql,
                            $reasonBindings
                        ): void {
                            $signals->whereRaw($statusSql, $statusBindings)
                                ->orWhereRaw($handleSql, $handleBindings)
                                ->orWhereRaw($reasonSql, $reasonBindings);
                        });
                });
        });
    }

    /**
     * @param  list<string>  $values
     * @return array{string,list<string>}
     */
    private function lowerInSql(string $column, array $values): array
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            "lower(coalesce({$column}, '')) in ({$placeholders})",
            array_map(static fn (string $value): string => strtolower($value), $values),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array{string,list<string>}
     */
    private function lowerNotInSql(string $column, array $values): array
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            "(lower(coalesce({$column}, '')) = '' or lower(coalesce({$column}, '')) not in ({$placeholders}))",
            array_map(static fn (string $value): string => strtolower($value), $values),
        ];
    }

    private function isReadySnapshot(object $row): bool
    {
        $status = strtolower(trim((string) ($row->status ?? '')));
        if (in_array($status, self::READY_SNAPSHOT_STATUSES, true)) {
            return true;
        }

        foreach (['report_full_json', 'report_free_json', 'report_json'] as $column) {
            $value = $row->{$column} ?? null;
            if ($this->hasReadablePayload($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasReadablePayload(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (! is_string($value)) {
            return false;
        }

        return trim($value) !== '' && trim($value) !== 'null' && trim($value) !== '{}';
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
                return CarbonImmutable::parse($candidate)->toIso8601String();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function minTimestamp(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return CarbonImmutable::parse($left)->lessThanOrEqualTo(CarbonImmutable::parse($right))
            ? $left
            : $right;
    }
}
