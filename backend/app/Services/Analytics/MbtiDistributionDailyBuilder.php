<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class MbtiDistributionDailyBuilder
{
    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @return array{
     *     type_rows:list<array<string,mixed>>,
     *     axis_rows:list<array<string,mixed>>,
     *     attempted_type_rows:int,
     *     attempted_axis_rows:int,
     *     source_results:int,
     *     source_results_with_at:int,
     *     at_authority_complete:bool,
     *     org_scope:list<int>,
     *     locale_scope:list<string>,
     *     scale_scope:array{scale_code:string,scale_code_v2:string,scale_uid:?string},
     *     from:string,
     *     to:string
     * }
     */
    public function build(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $locales = [],
    ): array {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $normalizedLocales = $this->normalizeLocales($locales);
        $scaleScope = $this->support->canonicalScale();

        if (! SchemaBaseline::hasTable('results') || ! SchemaBaseline::hasTable('attempts')) {
            return [
                'type_rows' => [],
                'axis_rows' => [],
                'attempted_type_rows' => 0,
                'attempted_axis_rows' => 0,
                'source_results' => 0,
                'source_results_with_at' => 0,
                'at_authority_complete' => false,
                'org_scope' => $normalizedOrgIds,
                'locale_scope' => $normalizedLocales,
                'scale_scope' => $scaleScope,
                'from' => $fromAt->toDateString(),
                'to' => $toAt->toDateString(),
            ];
        }

        $typeAggregates = [];
        $axisAggregates = [];
        $sourceResults = 0;
        $sourceResultsWithAt = 0;

        foreach ($this->authoritativeResultCursor($fromAt, $toAt, $normalizedOrgIds, $normalizedLocales, $scaleScope) as $row) {
            $projected = $this->projectRow($row, $scaleScope);
            if ($projected === null) {
                continue;
            }

            $sourceResults++;
            if (isset($projected['axis_sides']['AT'])) {
                $sourceResultsWithAt++;
            }

            $this->accumulateTypeRow($typeAggregates, $projected);
            $this->accumulateAxisRows($axisAggregates, $projected);
        }

        $typeRows = $this->finalizeTypeRows($typeAggregates);
        $axisRows = $this->finalizeAxisRows($axisAggregates);

        return [
            'type_rows' => $typeRows,
            'axis_rows' => $axisRows,
            'attempted_type_rows' => count($typeRows),
            'attempted_axis_rows' => count($axisRows),
            'source_results' => $sourceResults,
            'source_results_with_at' => $sourceResultsWithAt,
            'at_authority_complete' => $sourceResults > 0 && $sourceResults === $sourceResultsWithAt,
            'org_scope' => $normalizedOrgIds,
            'locale_scope' => $normalizedLocales,
            'scale_scope' => $scaleScope,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @return array{
     *     type_rows:list<array<string,mixed>>,
     *     axis_rows:list<array<string,mixed>>,
     *     attempted_type_rows:int,
     *     attempted_axis_rows:int,
     *     source_results:int,
     *     source_results_with_at:int,
     *     at_authority_complete:bool,
     *     org_scope:list<int>,
     *     locale_scope:list<string>,
     *     scale_scope:array{scale_code:string,scale_code_v2:string,scale_uid:?string},
     *     from:string,
     *     to:string,
     *     deleted_type_rows:int,
     *     deleted_axis_rows:int,
     *     upserted_type_rows:int,
     *     upserted_axis_rows:int,
     *     dry_run:bool
     * }
     */
    public function refresh(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $locales = [],
        bool $dryRun = false,
    ): array {
        $payload = $this->build($from, $to, $orgIds, $locales);
        $deletedTypeRows = 0;
        $deletedAxisRows = 0;
        $upsertedTypeRows = 0;
        $upsertedAxisRows = 0;

        if (! $dryRun) {
            DB::transaction(function () use (
                $payload,
                &$deletedTypeRows,
                &$deletedAxisRows,
                &$upsertedTypeRows,
                &$upsertedAxisRows
            ): void {
                $deletedTypeRows = $this->deleteScope(
                    'analytics_mbti_type_daily',
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['locale_scope'],
                    $payload['scale_scope']['scale_code']
                );
                $deletedAxisRows = $this->deleteScope(
                    'analytics_axis_daily',
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['locale_scope'],
                    $payload['scale_scope']['scale_code']
                );

                if ($payload['type_rows'] !== []) {
                    DB::table('analytics_mbti_type_daily')->upsert(
                        $payload['type_rows'],
                        [
                            'day',
                            'org_id',
                            'locale',
                            'region',
                            'scale_code',
                            'content_package_version',
                            'scoring_spec_version',
                            'norm_version',
                            'type_code',
                        ],
                        [
                            'results_count',
                            'distinct_attempts_with_results',
                            'last_refreshed_at',
                            'updated_at',
                        ]
                    );
                    $upsertedTypeRows = count($payload['type_rows']);
                }

                if ($payload['axis_rows'] !== []) {
                    DB::table('analytics_axis_daily')->upsert(
                        $payload['axis_rows'],
                        [
                            'day',
                            'org_id',
                            'locale',
                            'region',
                            'scale_code',
                            'content_package_version',
                            'scoring_spec_version',
                            'norm_version',
                            'axis_code',
                            'side_code',
                        ],
                        [
                            'results_count',
                            'distinct_attempts_with_results',
                            'last_refreshed_at',
                            'updated_at',
                        ]
                    );
                    $upsertedAxisRows = count($payload['axis_rows']);
                }
            });
        }

        return $payload + [
            'deleted_type_rows' => $deletedTypeRows,
            'deleted_axis_rows' => $deletedAxisRows,
            'upserted_type_rows' => $upsertedTypeRows,
            'upserted_axis_rows' => $upsertedAxisRows,
            'dry_run' => $dryRun,
        ];
    }

    public function __construct(
        private readonly MbtiInsightsSupport $support,
    ) {}

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     */
    private function authoritativeResultCursor(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $locales,
        array $scaleScope,
    ): \Traversable {
        $query = DB::table('results as results')
            ->join('attempts as attempts', 'attempts.id', '=', 'results.attempt_id')
            ->select([
                'results.id as result_id',
                'results.attempt_id',
                'results.type_code',
                'results.scale_code as result_scale_code',
                'results.org_id as result_org_id',
                'attempts.scale_code as attempt_scale_code',
                'attempts.org_id as attempt_org_id',
                'attempts.locale',
                'attempts.region',
                'attempts.norm_version',
            ])
            ->selectRaw('coalesce(results.computed_at, results.created_at, results.updated_at) as activity_at')
            ->whereRaw('coalesce(results.computed_at, results.created_at, results.updated_at) >= ?', [$fromAt->toDateTimeString()])
            ->whereRaw('coalesce(results.computed_at, results.created_at, results.updated_at) <= ?', [$toAt->toDateTimeString()])
            ->whereNotNull('results.type_code')
            ->where('results.type_code', '<>', '')
            ->orderBy('results.id');

        if (SchemaBaseline::hasColumn('results', 'is_valid')) {
            $query->where('results.is_valid', true);
        }

        if (SchemaBaseline::hasColumn('results', 'scale_code_v2')) {
            $query->addSelect('results.scale_code_v2 as result_scale_code_v2');
        }
        if (SchemaBaseline::hasColumn('results', 'scale_uid')) {
            $query->addSelect('results.scale_uid as result_scale_uid');
        }
        if (SchemaBaseline::hasColumn('results', 'scores_pct')) {
            $query->addSelect('results.scores_pct');
        }
        if (SchemaBaseline::hasColumn('results', 'content_package_version')) {
            $query->addSelect('results.content_package_version as result_content_package_version');
        }
        if (SchemaBaseline::hasColumn('results', 'scoring_spec_version')) {
            $query->addSelect('results.scoring_spec_version as result_scoring_spec_version');
        }
        if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
            $query->addSelect('attempts.scale_code_v2 as attempt_scale_code_v2');
        }
        if (SchemaBaseline::hasColumn('attempts', 'scale_uid')) {
            $query->addSelect('attempts.scale_uid as attempt_scale_uid');
        }
        if (SchemaBaseline::hasColumn('attempts', 'content_package_version')) {
            $query->addSelect('attempts.content_package_version as attempt_content_package_version');
        }
        if (SchemaBaseline::hasColumn('attempts', 'scoring_spec_version')) {
            $query->addSelect('attempts.scoring_spec_version as attempt_scoring_spec_version');
        }

        $this->applyCanonicalScaleFilter($query, 'results', 'results', $scaleScope);
        $this->applyCanonicalScaleFilter($query, 'attempts', 'attempts', $scaleScope);

        if ($orgIds !== []) {
            $query->where(function (QueryBuilder $builder) use ($orgIds): void {
                $builder
                    ->whereIn('attempts.org_id', $orgIds)
                    ->orWhereIn('results.org_id', $orgIds);
            });
        }

        if ($locales !== []) {
            $query->whereIn('attempts.locale', $locales);
        }

        return $query->cursor();
    }

    /**
     * @param  array{scale_code:string,scale_code_v2:string,scale_uid:?string}  $scaleScope
     */
    private function projectRow(object $row, array $scaleScope): ?array
    {
        $typeVariant = $this->support->normalizeTypeVariant((string) ($row->type_code ?? ''));
        if ($typeVariant === null) {
            return null;
        }

        $activityAt = trim((string) ($row->activity_at ?? ''));
        if ($activityAt === '') {
            return null;
        }

        $scoresPct = $this->decodeJsonAssoc($row->scores_pct ?? null);
        $axisSides = $this->support->deriveAxisSides($scoresPct, (string) ($row->type_code ?? ''));

        $orgId = max(0, (int) ($row->attempt_org_id ?? 0));
        if ($orgId <= 0) {
            $orgId = max(0, (int) ($row->result_org_id ?? 0));
        }

        return [
            'day' => CarbonImmutable::parse($activityAt)->toDateString(),
            'org_id' => $orgId,
            'locale' => $this->normalizeDimension((string) ($row->locale ?? 'unknown')),
            'region' => $this->normalizeDimension((string) ($row->region ?? 'unknown')),
            'scale_code' => $scaleScope['scale_code'],
            'content_package_version' => $this->normalizeDimension(
                (string) (($row->attempt_content_package_version ?? null) ?: ($row->result_content_package_version ?? ''))
            ),
            'scoring_spec_version' => $this->normalizeDimension(
                (string) (($row->attempt_scoring_spec_version ?? null) ?: ($row->result_scoring_spec_version ?? ''))
            ),
            'norm_version' => $this->normalizeDimension((string) ($row->norm_version ?? '')),
            'type_code' => $typeVariant['base'],
            'attempt_id' => (string) ($row->attempt_id ?? ''),
            'axis_sides' => $axisSides,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $aggregates
     * @param  array<string,mixed>  $projected
     */
    private function accumulateTypeRow(array &$aggregates, array $projected): void
    {
        $key = implode('|', [
            $projected['day'],
            (string) $projected['org_id'],
            $projected['locale'],
            $projected['region'],
            $projected['scale_code'],
            $projected['content_package_version'],
            $projected['scoring_spec_version'],
            $projected['norm_version'],
            $projected['type_code'],
        ]);

        if (! isset($aggregates[$key])) {
            $aggregates[$key] = [
                'day' => $projected['day'],
                'org_id' => $projected['org_id'],
                'locale' => $projected['locale'],
                'region' => $projected['region'],
                'scale_code' => $projected['scale_code'],
                'content_package_version' => $projected['content_package_version'],
                'scoring_spec_version' => $projected['scoring_spec_version'],
                'norm_version' => $projected['norm_version'],
                'type_code' => $projected['type_code'],
                'results_count' => 0,
                'attempt_ids' => [],
            ];
        }

        $aggregates[$key]['results_count']++;
        $aggregates[$key]['attempt_ids'][$projected['attempt_id']] = true;
    }

    /**
     * @param  array<string,array<string,mixed>>  $aggregates
     * @param  array<string,mixed>  $projected
     */
    private function accumulateAxisRows(array &$aggregates, array $projected): void
    {
        foreach ((array) ($projected['axis_sides'] ?? []) as $axisCode => $sideCode) {
            $key = implode('|', [
                $projected['day'],
                (string) $projected['org_id'],
                $projected['locale'],
                $projected['region'],
                $projected['scale_code'],
                $projected['content_package_version'],
                $projected['scoring_spec_version'],
                $projected['norm_version'],
                $axisCode,
                $sideCode,
            ]);

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = [
                    'day' => $projected['day'],
                    'org_id' => $projected['org_id'],
                    'locale' => $projected['locale'],
                    'region' => $projected['region'],
                    'scale_code' => $projected['scale_code'],
                    'content_package_version' => $projected['content_package_version'],
                    'scoring_spec_version' => $projected['scoring_spec_version'],
                    'norm_version' => $projected['norm_version'],
                    'axis_code' => $axisCode,
                    'side_code' => $sideCode,
                    'results_count' => 0,
                    'attempt_ids' => [],
                ];
            }

            $aggregates[$key]['results_count']++;
            $aggregates[$key]['attempt_ids'][$projected['attempt_id']] = true;
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $aggregates
     * @return list<array<string,mixed>>
     */
    private function finalizeTypeRows(array $aggregates): array
    {
        $timestamp = now();
        $rows = [];

        foreach ($aggregates as $row) {
            $rows[] = [
                'day' => $row['day'],
                'org_id' => $row['org_id'],
                'locale' => $row['locale'],
                'region' => $row['region'],
                'scale_code' => $row['scale_code'],
                'content_package_version' => $row['content_package_version'],
                'scoring_spec_version' => $row['scoring_spec_version'],
                'norm_version' => $row['norm_version'],
                'type_code' => $row['type_code'],
                'results_count' => (int) ($row['results_count'] ?? 0),
                'distinct_attempts_with_results' => count((array) ($row['attempt_ids'] ?? [])),
                'last_refreshed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['day'], $a['locale'], $a['type_code']] <=> [$b['day'], $b['locale'], $b['type_code']];
        });

        return $rows;
    }

    /**
     * @param  array<string,array<string,mixed>>  $aggregates
     * @return list<array<string,mixed>>
     */
    private function finalizeAxisRows(array $aggregates): array
    {
        $timestamp = now();
        $rows = [];

        foreach ($aggregates as $row) {
            $rows[] = [
                'day' => $row['day'],
                'org_id' => $row['org_id'],
                'locale' => $row['locale'],
                'region' => $row['region'],
                'scale_code' => $row['scale_code'],
                'content_package_version' => $row['content_package_version'],
                'scoring_spec_version' => $row['scoring_spec_version'],
                'norm_version' => $row['norm_version'],
                'axis_code' => $row['axis_code'],
                'side_code' => $row['side_code'],
                'results_count' => (int) ($row['results_count'] ?? 0),
                'distinct_attempts_with_results' => count((array) ($row['attempt_ids'] ?? [])),
                'last_refreshed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['day'], $a['locale'], $a['axis_code'], $a['side_code']] <=> [$b['day'], $b['locale'], $b['axis_code'], $b['side_code']];
        });

        return $rows;
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     */
    private function deleteScope(
        string $table,
        string $from,
        string $to,
        array $orgIds,
        array $locales,
        string $scaleCode,
    ): int {
        if (! SchemaBaseline::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)
            ->whereBetween('day', [$from, $to])
            ->where('scale_code', $scaleCode);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        if ($locales !== []) {
            $query->whereIn('locale', $locales);
        }

        return $query->delete();
    }

    /**
     * @param  array{scale_code:string,scale_code_v2:string,scale_uid:?string}  $scaleScope
     */
    private function applyCanonicalScaleFilter(
        QueryBuilder $query,
        string $tableName,
        string $alias,
        array $scaleScope,
    ): void {
        $query->where(function (QueryBuilder $builder) use ($tableName, $alias, $scaleScope): void {
            $builder->whereRaw('1 = 0');

            if (SchemaBaseline::hasColumn($tableName, 'scale_uid') && $scaleScope['scale_uid'] !== null) {
                $builder->orWhere($alias.'.scale_uid', $scaleScope['scale_uid']);
            }

            if (SchemaBaseline::hasColumn($tableName, 'scale_code_v2') && $scaleScope['scale_code_v2'] !== '') {
                $builder->orWhereRaw('upper('.$alias.'.scale_code_v2) = ?', [$scaleScope['scale_code_v2']]);
            }

            if (SchemaBaseline::hasColumn($tableName, 'scale_code')) {
                $builder->orWhereRaw('upper('.$alias.'.scale_code) = ?', [$scaleScope['scale_code']]);
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonAssoc(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDimension(string $value): string
    {
        $normalized = trim($value);

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @param  list<int|string>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $set = [];

        foreach ($orgIds as $orgId) {
            $normalized = max(0, (int) $orgId);
            if ($normalized <= 0) {
                continue;
            }
            $set[$normalized] = true;
        }

        return array_values(array_keys($set));
    }

    /**
     * @param  list<mixed>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $set = [];

        foreach ($locales as $locale) {
            $normalized = trim((string) $locale);
            if ($normalized === '') {
                continue;
            }
            $set[$normalized] = true;
        }

        return array_values(array_keys($set));
    }
}
