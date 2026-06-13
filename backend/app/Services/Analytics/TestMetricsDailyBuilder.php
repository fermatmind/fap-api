<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class TestMetricsDailyBuilder
{
    private const SUCCESS_STATES = [
        'succeeded',
        'success',
        'ready',
        'completed',
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

        $started = $this->collectStartedEntries($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes);
        $successful = $this->collectSuccessfulEntries($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes);
        $failed = $this->collectFailedEntries($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes);

        $attemptIds = $this->candidateAttemptIds($started, $successful, $failed);
        $dimensions = $this->loadAttemptDimensions($attemptIds, $normalizedOrgIds, $normalizedScaleCodes);
        $rows = $this->aggregateRows($started, $successful, $failed, $dimensions);

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

                DB::table('analytics_test_metrics_daily')->upsert(
                    $rows,
                    ['day', 'org_id', 'scale_code', 'scale_code_v2', 'form_code', 'locale'],
                    [
                        'scale_uid',
                        'started_attempts',
                        'successful_attempts',
                        'failed_attempts',
                        'total_attempts',
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
     * @param  list<array{attempt_id:string,day:string}>  ...$entryLists
     * @return list<string>
     */
    private function candidateAttemptIds(array ...$entryLists): array
    {
        $attemptIds = [];
        foreach ($entryLists as $entries) {
            foreach ($entries as $entry) {
                $attemptId = trim((string) ($entry['attempt_id'] ?? ''));
                if ($attemptId !== '') {
                    $attemptIds[$attemptId] = true;
                }
            }
        }

        return array_keys($attemptIds);
    }

    /**
     * @param  list<string>  $attemptIds
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return array<string,array{org_id:int,scale_code:string,scale_code_v2:string,scale_uid:string,form_code:string,locale:string}>
     */
    private function loadAttemptDimensions(array $attemptIds, array $orgIds, array $scaleCodes): array
    {
        if ($attemptIds === [] || ! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $query = DB::table('attempts')
            ->whereIn('id', $attemptIds)
            ->select(['id', 'org_id', 'scale_code']);

        $this->applyOrgFilter($query, 'attempts', $orgIds);
        $this->applyScaleFilter($query, 'attempts', $scaleCodes);

        if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
            $query->addSelect('scale_code_v2');
        }
        if (SchemaBaseline::hasColumn('attempts', 'scale_uid')) {
            $query->addSelect('scale_uid');
        }
        if (SchemaBaseline::hasColumn('attempts', 'form_code')) {
            $query->addSelect('form_code');
        }
        if (SchemaBaseline::hasColumn('attempts', 'locale')) {
            $query->addSelect('locale');
        }

        $dimensions = [];
        foreach ($query->get() as $row) {
            $attemptId = trim((string) ($row->id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $scaleCode = $this->normalizeDimension($row->scale_code ?? null, 'unknown');
            $scaleCodeV2 = $this->normalizeDimension($row->scale_code_v2 ?? null, '');

            $dimensions[$attemptId] = [
                'org_id' => max(0, (int) ($row->org_id ?? 0)),
                'scale_code' => $scaleCode,
                'scale_code_v2' => $scaleCodeV2,
                'scale_uid' => $this->normalizeDimension($row->scale_uid ?? null, ''),
                'form_code' => $this->normalizeDimension($row->form_code ?? null, ''),
                'locale' => $this->normalizeLocale($row->locale ?? null),
            ];
        }

        return $dimensions;
    }

    /**
     * @param  list<array{attempt_id:string,day:string}>  $started
     * @param  list<array{attempt_id:string,day:string}>  $successful
     * @param  list<array{attempt_id:string,day:string}>  $failed
     * @param  array<string,array{org_id:int,scale_code:string,scale_code_v2:string,scale_uid:string,form_code:string,locale:string}>  $dimensions
     * @return array<string,array<string,mixed>>
     */
    private function aggregateRows(array $started, array $successful, array $failed, array $dimensions): array
    {
        $rows = [];

        $this->incrementRows($rows, $started, $dimensions, 'started_attempts');
        $this->incrementRows($rows, $successful, $dimensions, 'successful_attempts');
        $this->incrementRows($rows, $failed, $dimensions, 'failed_attempts');

        $now = now();
        foreach ($rows as &$row) {
            $row['total_attempts'] = (int) $row['successful_attempts'] + (int) $row['failed_attempts'];
            $row['last_refreshed_at'] = $now;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        ksort($rows);

        return $rows;
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @param  list<array{attempt_id:string,day:string}>  $entries
     * @param  array<string,array{org_id:int,scale_code:string,scale_code_v2:string,scale_uid:string,form_code:string,locale:string}>  $dimensions
     */
    private function incrementRows(array &$rows, array $entries, array $dimensions, string $metric): void
    {
        foreach ($entries as $entry) {
            $attemptId = trim((string) ($entry['attempt_id'] ?? ''));
            $day = trim((string) ($entry['day'] ?? ''));
            if ($attemptId === '' || $day === '' || ! isset($dimensions[$attemptId])) {
                continue;
            }

            $dimension = $dimensions[$attemptId];
            $key = implode('|', [
                $day,
                (string) $dimension['org_id'],
                $dimension['scale_code'],
                $dimension['scale_code_v2'],
                $dimension['form_code'],
                $dimension['locale'],
            ]);

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'day' => $day,
                    'org_id' => $dimension['org_id'],
                    'scale_code' => $dimension['scale_code'],
                    'scale_code_v2' => $dimension['scale_code_v2'],
                    'scale_uid' => $dimension['scale_uid'],
                    'form_code' => $dimension['form_code'],
                    'locale' => $dimension['locale'],
                    'started_attempts' => 0,
                    'successful_attempts' => 0,
                    'failed_attempts' => 0,
                    'total_attempts' => 0,
                ];
            }

            $rows[$key][$metric] = (int) $rows[$key][$metric] + 1;
        }
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return list<array{attempt_id:string,day:string}>
     */
    private function collectStartedEntries(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $scaleCodes
    ): array {
        if (! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $timeColumn = SchemaBaseline::hasColumn('attempts', 'started_at') ? 'started_at' : 'created_at';
        $query = DB::table('attempts')
            ->whereBetween($timeColumn, [$fromAt, $toAt])
            ->select(['id as attempt_id', $timeColumn.' as metric_at']);

        $this->applyOrgFilter($query, 'attempts', $orgIds);
        $this->applyScaleFilter($query, 'attempts', $scaleCodes);

        return $this->entriesFromRows($query->get());
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return list<array{attempt_id:string,day:string}>
     */
    private function collectSuccessfulEntries(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $scaleCodes
    ): array {
        $entries = [];

        if (SchemaBaseline::hasTable('attempts') && SchemaBaseline::hasColumn('attempts', 'submitted_at')) {
            $query = DB::table('attempts')
                ->whereBetween('submitted_at', [$fromAt, $toAt])
                ->select(['id as attempt_id', 'submitted_at as metric_at']);
            $this->applyOrgFilter($query, 'attempts', $orgIds);
            $this->applyScaleFilter($query, 'attempts', $scaleCodes);
            $entries = $this->mergeDistinctEntries($entries, $this->entriesFromRows($query->get()));
        }

        if (SchemaBaseline::hasTable('attempt_submissions')) {
            $timeExpression = 'COALESCE(attempt_submissions.finished_at, attempt_submissions.updated_at, attempt_submissions.created_at)';
            $query = DB::table('attempt_submissions')
                ->join('attempts', 'attempts.id', '=', 'attempt_submissions.attempt_id')
                ->whereIn('attempt_submissions.state', self::SUCCESS_STATES)
                ->whereBetween(DB::raw($timeExpression), [$fromAt, $toAt])
                ->select([
                    'attempt_submissions.attempt_id as attempt_id',
                    DB::raw($timeExpression.' as metric_at'),
                ]);
            $this->applyOrgFilter($query, 'attempt_submissions', $orgIds);
            $this->applyScaleFilter($query, 'attempts', $scaleCodes);
            $entries = $this->mergeDistinctEntries($entries, $this->entriesFromRows($query->get()));
        }

        if (SchemaBaseline::hasTable('results')) {
            $timeColumn = SchemaBaseline::hasColumn('results', 'computed_at') ? 'computed_at' : 'created_at';
            $query = DB::table('results')
                ->join('attempts', 'attempts.id', '=', 'results.attempt_id')
                ->whereBetween('results.'.$timeColumn, [$fromAt, $toAt])
                ->select([
                    'results.attempt_id as attempt_id',
                    'results.'.$timeColumn.' as metric_at',
                ]);
            $this->applyOrgFilter($query, 'results', $orgIds);
            $this->applyScaleFilter($query, 'attempts', $scaleCodes);
            $entries = $this->mergeDistinctEntries($entries, $this->entriesFromRows($query->get()));
        }

        return $entries;
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @return list<array{attempt_id:string,day:string}>
     */
    private function collectFailedEntries(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $scaleCodes
    ): array {
        if (! SchemaBaseline::hasTable('attempt_submissions')) {
            return [];
        }

        $timeExpression = 'COALESCE(attempt_submissions.finished_at, attempt_submissions.updated_at, attempt_submissions.created_at)';
        $query = DB::table('attempt_submissions')
            ->join('attempts', 'attempts.id', '=', 'attempt_submissions.attempt_id')
            ->where('attempt_submissions.state', 'failed')
            ->whereBetween(DB::raw($timeExpression), [$fromAt, $toAt])
            ->select([
                'attempt_submissions.attempt_id as attempt_id',
                DB::raw($timeExpression.' as metric_at'),
            ]);

        $this->applyOrgFilter($query, 'attempt_submissions', $orgIds);
        $this->applyScaleFilter($query, 'attempts', $scaleCodes);

        return $this->entriesFromRows($query->get());
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{attempt_id:string,day:string}>
     */
    private function entriesFromRows(iterable $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $metricAt = $row->metric_at ?? null;
            if ($attemptId === '' || $metricAt === null) {
                continue;
            }

            $day = CarbonImmutable::parse($metricAt)->toDateString();
            $entries[$attemptId.'|'.$day] = [
                'attempt_id' => $attemptId,
                'day' => $day,
            ];
        }

        return array_values($entries);
    }

    /**
     * @param  list<array{attempt_id:string,day:string}>  $current
     * @param  list<array{attempt_id:string,day:string}>  $incoming
     * @return list<array{attempt_id:string,day:string}>
     */
    private function mergeDistinctEntries(array $current, array $incoming): array
    {
        $merged = [];
        foreach (array_merge($current, $incoming) as $entry) {
            $attemptId = trim((string) ($entry['attempt_id'] ?? ''));
            $day = trim((string) ($entry['day'] ?? ''));
            if ($attemptId !== '' && $day !== '') {
                $merged[$attemptId.'|'.$day] = [
                    'attempt_id' => $attemptId,
                    'day' => $day,
                ];
            }
        }

        return array_values($merged);
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function applyOrgFilter(QueryBuilder $query, string $table, array $orgIds): void
    {
        if ($orgIds !== [] && SchemaBaseline::hasColumn($table, 'org_id')) {
            $query->whereIn($table.'.org_id', $orgIds);
        }
    }

    /**
     * @param  list<string>  $scaleCodes
     */
    private function applyScaleFilter(QueryBuilder $query, string $table, array $scaleCodes): void
    {
        if ($scaleCodes === []) {
            return;
        }

        $query->where(function (QueryBuilder $nested) use ($table, $scaleCodes): void {
            if (SchemaBaseline::hasColumn($table, 'scale_code')) {
                $nested->orWhereIn(DB::raw('UPPER('.$table.'.scale_code)'), $scaleCodes);
            }
            if (SchemaBaseline::hasColumn($table, 'scale_code_v2')) {
                $nested->orWhereIn(DB::raw('UPPER('.$table.'.scale_code_v2)'), $scaleCodes);
            }
        });
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     */
    private function deleteScope(string $from, string $to, array $orgIds, array $scaleCodes): int
    {
        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            return 0;
        }

        $query = DB::table('analytics_test_metrics_daily')->whereBetween('day', [$from, $to]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        if ($scaleCodes !== []) {
            $query->where(function (QueryBuilder $nested) use ($scaleCodes): void {
                $nested->whereIn(DB::raw('UPPER(scale_code)'), $scaleCodes)
                    ->orWhereIn(DB::raw('UPPER(scale_code_v2)'), $scaleCodes);
            });
        }

        return $query->delete();
    }

    /**
     * @param  list<int|string>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $normalized = [];
        foreach ($orgIds as $orgId) {
            $value = (int) $orgId;
            if ($value >= 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  list<string>  $scaleCodes
     * @return list<string>
     */
    private function normalizeScaleCodes(array $scaleCodes): array
    {
        $normalized = [];
        foreach ($scaleCodes as $scaleCode) {
            $value = strtoupper(trim((string) $scaleCode));
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeDimension(mixed $value, string $fallback): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function normalizeLocale(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : 'unknown';
    }
}
