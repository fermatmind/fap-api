<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class QualityResearchInsightsSupport
{
    /**
     * @return array{
     *   scaleOptions:array<string,string>,
     *   localeOptions:array<string,string>,
     *   regionOptions:array<string,string>,
     *   contentPackageVersionOptions:array<string,string>,
     *   scoringSpecVersionOptions:array<string,string>,
     *   normVersionOptions:array<string,string>
     * }
     */
    public function filterOptions(int $orgId): array
    {
        $scaleOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'scale_code'),
            $this->globalDistinctValues('big5_psychometrics_reports', 'scale_code'),
            $this->globalDistinctValues('eq60_psychometrics_reports', 'scale_code'),
            $this->globalDistinctValues('sds_psychometrics_reports', 'scale_code'),
            $this->globalDistinctValues('scale_norms_versions', 'scale_code'),
            ['BIG5_OCEAN', 'EQ_60', 'SDS_20'],
        ]);

        $localeOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'locale'),
            $this->globalDistinctValues('big5_psychometrics_reports', 'locale'),
            $this->globalDistinctValues('eq60_psychometrics_reports', 'locale'),
            $this->globalDistinctValues('sds_psychometrics_reports', 'locale'),
            $this->globalDistinctValues('scale_norms_versions', 'locale'),
        ]);

        $regionOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'region'),
            $this->globalDistinctValues('big5_psychometrics_reports', 'region'),
            $this->globalDistinctValues('eq60_psychometrics_reports', 'region'),
            $this->globalDistinctValues('sds_psychometrics_reports', 'region'),
            $this->globalDistinctValues('scale_norms_versions', 'region'),
        ]);

        $contentOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'content_package_version'),
        ]);

        $scoringOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'scoring_spec_version'),
        ]);

        $normOptions = $this->mergeOptionValues([
            $this->attemptDistinctValues($orgId, 'norm_version'),
            $this->globalDistinctValues('big5_psychometrics_reports', 'norms_version'),
            $this->globalDistinctValues('eq60_psychometrics_reports', 'norms_version'),
            $this->globalDistinctValues('sds_psychometrics_reports', 'norms_version'),
            $this->globalDistinctValues('scale_norms_versions', 'version'),
        ]);

        return [
            'scaleOptions' => $this->toOptionMap($scaleOptions),
            'localeOptions' => $this->toOptionMap($localeOptions),
            'regionOptions' => $this->toOptionMap($regionOptions),
            'contentPackageVersionOptions' => $this->toOptionMap($contentOptions),
            'scoringSpecVersionOptions' => $this->toOptionMap($scoringOptions),
            'normVersionOptions' => $this->toOptionMap($normOptions),
        ];
    }

    /**
     * @param  array<string,string>  $filters
     * @param  array<string,mixed>  $localFilters
     * @return array{
     *   has_data:bool,
     *   kpis:list<array<string,mixed>>,
     *   daily_rows:list<array<string,mixed>>,
     *   scale_rows:list<array<string,mixed>>,
     *   flag_rows:list<array<string,mixed>>,
     *   warnings:list<string>,
     *   notes:list<string>
     * }
     */
    public function qualityPayload(int $orgId, array $filters, array $localFilters = []): array
    {
        $warnings = [];
        $notes = [
            'Quality KPI cards stay rooted in analytics_scale_quality_daily. Local quality filters shape the tables only; drill-through stays in Attempts / Results Explorer.',
            'Cross-scale validity is derived from stable quality.level buckets first, then falls back to results.is_valid only when quality.level is missing.',
            'Longstring, straightlining, extreme responding, and inconsistency stay first-phase reference counters. Some flags are available on subset scales only.',
        ];

        if (! SchemaBaseline::hasTable('analytics_scale_quality_daily')) {
            return [
                'has_data' => false,
                'kpis' => [],
                'daily_rows' => [],
                'scale_rows' => [],
                'flag_rows' => [],
                'warnings' => ['analytics_scale_quality_daily is missing. Run php artisan migrate and php artisan analytics:refresh-quality-daily first.'],
                'notes' => $notes,
            ];
        }

        $rows = $this->qualityBaseQuery($orgId, $filters)->get([
            'day',
            'scale_code',
            'locale',
            'region',
            'content_package_version',
            'scoring_spec_version',
            'norm_version',
            'started_attempts',
            'completed_attempts',
            'results_count',
            'valid_results_count',
            'invalid_results_count',
            'quality_a_count',
            'quality_b_count',
            'quality_c_count',
            'quality_d_count',
            'crisis_alert_count',
            'speeding_count',
            'longstring_count',
            'straightlining_count',
            'extreme_count',
            'inconsistency_count',
            'warnings_count',
            'last_refreshed_at',
        ])->map(fn (object $row): array => $this->qualityRowPayload($row));

        if ($rows->isEmpty()) {
            $warnings[] = 'No quality daily rows match the current org/date/version scope. Refresh the read model or widen the filter range.';
        }

        $filteredRows = $this->applyQualityLocalFilters($rows, $localFilters);
        if ($rows->isNotEmpty() && $filteredRows->isEmpty()) {
            $warnings[] = 'Local quality filters removed every aggregate row in the current scope.';
        }

        return [
            'has_data' => $rows->isNotEmpty(),
            'kpis' => $this->buildQualityKpis($rows),
            'daily_rows' => $this->buildDailyQualityRows($filteredRows),
            'scale_rows' => $this->buildScaleQualityRows($filteredRows),
            'flag_rows' => $this->buildQualityFlagRows($filteredRows),
            'warnings' => $warnings,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{
     *   has_data:bool,
     *   rows:list<array<string,mixed>>,
     *   warnings:list<string>,
     *   notes:list<string>
     * }
     */
    public function psychometricsPayload(array $filters): array
    {
        $rows = [];
        $warnings = [];
        $notes = [
            'Psychometric snapshots are internal research reference only. Small-sample rows stay reference-only and should not be treated as default product truth.',
            'Content package and scoring spec filters are not direct psychometrics fact dimensions in v1. Locale / region / norm_version remain the stable selectors here.',
        ];

        foreach ($this->psychometricSourceDefinitions() as $definition) {
            $table = (string) $definition['table'];
            if (! SchemaBaseline::hasTable($table)) {
                $warnings[] = sprintf('%s is missing.', $table);

                continue;
            }

            $query = DB::table($table)
                ->where('scale_code', $definition['scale_code'])
                ->orderByDesc('generated_at')
                ->orderByDesc('created_at');

            $this->applyPsychometricFilters($query, $filters);

            $row = $query->first([
                'scale_code',
                'locale',
                'region',
                'norms_version',
                'time_window',
                'sample_n',
                'metrics_json',
                'generated_at',
                'created_at',
            ]);

            if (! $row) {
                continue;
            }

            $metrics = $this->decodeJson($row->metrics_json ?? null);
            $scaleCode = strtoupper(trim((string) ($row->scale_code ?? $definition['scale_code'])));
            $threshold = $this->psychometricSampleThreshold($scaleCode);
            $summary = $this->psychometricSummary($scaleCode, $metrics);
            $sampleN = max(0, (int) ($row->sample_n ?? 0));

            $rows[] = [
                'scale_code' => $scaleCode,
                'locale' => $this->stringOrUnknown($row->locale ?? null),
                'region' => $this->stringOrUnknown($row->region ?? null),
                'norm_version' => $this->stringOrUnknown($row->norms_version ?? null),
                'time_window' => $this->stringOrUnknown($row->time_window ?? null),
                'sample_n' => $sampleN,
                'min_display_samples' => $threshold,
                'latest_snapshot_at' => $this->formatDateTime($row->generated_at ?? $row->created_at ?? null),
                'reference_state' => $sampleN >= $threshold
                    ? 'Internal reference'
                    : 'Internal reference - below display threshold',
                'summary_primary' => $summary['primary'],
                'summary_secondary' => $summary['secondary'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $a['scale_code'], (string) $b['scale_code']);
        });

        return [
            'has_data' => $rows !== [],
            'rows' => $rows,
            'warnings' => $warnings,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{
     *   has_data:bool,
     *   coverage_rows:list<array<string,mixed>>,
     *   rollout_rows:list<array<string,mixed>>,
     *   drift_rows:list<array<string,mixed>>,
     *   warnings:list<string>,
     *   notes:list<string>
     * }
     */
    public function normsPayload(int $orgId, array $filters): array
    {
        $warnings = [];
        $notes = [
            'Norm version coverage is the hard object layer in v1. Drift compare and rollout observation coverage are internal diagnostics, not hard authority metrics.',
            'Clinical / crisis-sensitive slices remain aggregate-only here. Raw answer payloads, raw checks JSON, and scoring traces stay outside the page.',
        ];

        $coverageRows = $this->normCoverageRows($filters, $warnings);
        $rolloutRows = $this->rolloutRows($orgId, $filters, $warnings);
        $driftRows = $this->driftCompareRows($filters, $warnings);

        return [
            'has_data' => $coverageRows !== [] || $rolloutRows !== [] || $driftRows !== [],
            'coverage_rows' => $coverageRows,
            'rollout_rows' => $rolloutRows,
            'drift_rows' => $driftRows,
            'warnings' => $warnings,
            'notes' => $notes,
        ];
    }

    /**
     * @return list<string>
     */
    public function pageScopeNotes(): array
    {
        return [
            'AIC-08 is admin-only / internal-only. Quality uses analytics_scale_quality_daily for daily summary and keeps per-attempt drill-through in existing Attempts / Results explorers.',
            'Psychometrics is snapshot-driven. Norms coverage reads scale_norms_versions + scale_norm_stats directly. Drift stays a compare reference, not a hard trend dashboard.',
            'Global filters keep version context explicit. Content/scoring selectors are first-class on Quality and rollout observation coverage, but not direct psychometric snapshot dimensions in v1.',
        ];
    }

    /**
     * @param  list<list<string>>  $sources
     * @return list<string>
     */
    private function mergeOptionValues(array $sources): array
    {
        $values = [];
        foreach ($sources as $source) {
            foreach ($source as $value) {
                $normalized = trim((string) $value);
                if ($normalized === '' || strtolower($normalized) === 'unknown') {
                    continue;
                }

                $values[$normalized] = $normalized;
            }
        }

        $merged = array_values($values);
        sort($merged);

        return $merged;
    }

    /**
     * @param  list<string>  $values
     * @return array<string,string>
     */
    private function toOptionMap(array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = $value;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function attemptDistinctValues(int $orgId, string $column): array
    {
        if (! SchemaBaseline::hasTable('attempts') || ! SchemaBaseline::hasColumn('attempts', $column)) {
            return [];
        }

        $query = DB::table('attempts')
            ->where('org_id', $orgId)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column);

        return $query->pluck($column)->map(static fn ($value): string => (string) $value)->all();
    }

    /**
     * @return list<string>
     */
    private function globalDistinctValues(string $table, string $column): array
    {
        if (! SchemaBaseline::hasTable($table) || ! SchemaBaseline::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * @param  array<string,string>  $filters
     */
    private function qualityBaseQuery(int $orgId, array $filters): QueryBuilder
    {
        [$from, $to] = $this->resolvedRange($filters);
        $query = DB::table('analytics_scale_quality_daily')
            ->where('org_id', $orgId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        if (($filters['scale_code'] ?? 'all') !== 'all') {
            $query->where('scale_code', strtoupper((string) $filters['scale_code']));
        }
        if (($filters['locale'] ?? 'all') !== 'all') {
            $query->where('locale', (string) $filters['locale']);
        }
        if (($filters['region'] ?? 'all') !== 'all') {
            $query->where('region', (string) $filters['region']);
        }
        if (($filters['content_package_version'] ?? 'all') !== 'all') {
            $query->where('content_package_version', (string) $filters['content_package_version']);
        }
        if (($filters['scoring_spec_version'] ?? 'all') !== 'all') {
            $query->where('scoring_spec_version', (string) $filters['scoring_spec_version']);
        }
        if (($filters['norm_version'] ?? 'all') !== 'all') {
            $query->where('norm_version', (string) $filters['norm_version']);
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityRowPayload(object $row): array
    {
        $startedAttempts = max(0, (int) ($row->started_attempts ?? 0));
        $completedAttempts = max(0, (int) ($row->completed_attempts ?? 0));
        $resultsCount = max(0, (int) ($row->results_count ?? 0));
        $validResults = max(0, (int) ($row->valid_results_count ?? 0));
        $invalidResults = max(0, (int) ($row->invalid_results_count ?? 0));
        $qualityA = max(0, (int) ($row->quality_a_count ?? 0));
        $qualityB = max(0, (int) ($row->quality_b_count ?? 0));
        $qualityC = max(0, (int) ($row->quality_c_count ?? 0));
        $qualityD = max(0, (int) ($row->quality_d_count ?? 0));

        return [
            'day' => (string) ($row->day ?? ''),
            'scale_code' => strtoupper(trim((string) ($row->scale_code ?? 'UNKNOWN'))),
            'locale' => $this->stringOrUnknown($row->locale ?? null),
            'region' => $this->stringOrUnknown($row->region ?? null),
            'content_package_version' => $this->stringOrUnknown($row->content_package_version ?? null),
            'scoring_spec_version' => $this->stringOrUnknown($row->scoring_spec_version ?? null),
            'norm_version' => $this->stringOrUnknown($row->norm_version ?? null),
            'started_attempts' => $startedAttempts,
            'completed_attempts' => $completedAttempts,
            'results_count' => $resultsCount,
            'valid_results_count' => $validResults,
            'invalid_results_count' => $invalidResults,
            'quality_a_count' => $qualityA,
            'quality_b_count' => $qualityB,
            'quality_c_count' => $qualityC,
            'quality_d_count' => $qualityD,
            'crisis_alert_count' => max(0, (int) ($row->crisis_alert_count ?? 0)),
            'speeding_count' => max(0, (int) ($row->speeding_count ?? 0)),
            'longstring_count' => max(0, (int) ($row->longstring_count ?? 0)),
            'straightlining_count' => max(0, (int) ($row->straightlining_count ?? 0)),
            'extreme_count' => max(0, (int) ($row->extreme_count ?? 0)),
            'inconsistency_count' => max(0, (int) ($row->inconsistency_count ?? 0)),
            'warnings_count' => max(0, (int) ($row->warnings_count ?? 0)),
            'completion_rate' => $startedAttempts > 0 ? round($completedAttempts / $startedAttempts, 4) : null,
            'validity_rate' => $resultsCount > 0 ? round($validResults / $resultsCount, 4) : null,
            'quality_mix' => $this->qualityMixLabel($qualityA, $qualityB, $qualityC, $qualityD),
            'last_refreshed_at' => $this->formatDateTime($row->last_refreshed_at ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $localFilters
     */
    private function applyQualityLocalFilters(Collection $rows, array $localFilters): Collection
    {
        $qualityLevel = strtoupper(trim((string) ($localFilters['quality_level'] ?? 'all')));
        $onlyCrisis = (bool) ($localFilters['only_crisis'] ?? false);
        $onlyInvalid = (bool) ($localFilters['only_invalid'] ?? false);
        $onlyWarnings = (bool) ($localFilters['only_warnings'] ?? false);

        return $rows->filter(function (array $row) use ($qualityLevel, $onlyCrisis, $onlyInvalid, $onlyWarnings): bool {
            if ($qualityLevel !== '' && $qualityLevel !== 'ALL') {
                $field = match ($qualityLevel) {
                    'A' => 'quality_a_count',
                    'B' => 'quality_b_count',
                    'C' => 'quality_c_count',
                    'D' => 'quality_d_count',
                    default => null,
                };
                if ($field !== null && (int) ($row[$field] ?? 0) <= 0) {
                    return false;
                }
            }

            if ($onlyCrisis && (int) ($row['crisis_alert_count'] ?? 0) <= 0) {
                return false;
            }
            if ($onlyInvalid && (int) ($row['invalid_results_count'] ?? 0) <= 0) {
                return false;
            }
            if ($onlyWarnings && (int) ($row['warnings_count'] ?? 0) <= 0) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildQualityKpis(Collection $rows): array
    {
        $startedAttempts = (int) $rows->sum('started_attempts');
        $completedAttempts = (int) $rows->sum('completed_attempts');
        $resultsCount = (int) $rows->sum('results_count');
        $validResults = (int) $rows->sum('valid_results_count');
        $qualityA = (int) $rows->sum('quality_a_count');
        $qualityB = (int) $rows->sum('quality_b_count');
        $qualityC = (int) $rows->sum('quality_c_count');
        $qualityD = (int) $rows->sum('quality_d_count');

        return [
            [
                'label' => 'Sample size',
                'display_value' => number_format($resultsCount),
                'description' => 'Result-rooted count inside the current global scope.',
            ],
            [
                'label' => 'Completion rate',
                'display_value' => $this->formatRate($startedAttempts > 0 ? $completedAttempts / $startedAttempts : null),
                'description' => 'Completed attempts divided by started attempts.',
            ],
            [
                'label' => 'Validity pass rate',
                'display_value' => $this->formatRate($resultsCount > 0 ? $validResults / $resultsCount : null),
                'description' => 'Quality A/B share, with results.is_valid used only as fallback.',
            ],
            [
                'label' => 'Quality A / B / C / D',
                'display_value' => $this->qualityMixLabel($qualityA, $qualityB, $qualityC, $qualityD),
                'description' => 'Stable quality.level buckets across the selected scope.',
            ],
            [
                'label' => 'Crisis alerts',
                'display_value' => number_format((int) $rows->sum('crisis_alert_count')),
                'description' => 'Aggregate-only crisis signal count. No sensitive slice ranking in v1.',
            ],
            [
                'label' => 'Longstring',
                'display_value' => number_format((int) $rows->sum('longstring_count')),
                'description' => 'Reference counter from stable quality flags when present.',
            ],
            [
                'label' => 'Straightlining',
                'display_value' => number_format((int) $rows->sum('straightlining_count')),
                'description' => 'Reference counter from stable quality flags when present.',
            ],
            [
                'label' => 'Extreme / Inconsistency',
                'display_value' => number_format((int) $rows->sum('extreme_count')).' / '.number_format((int) $rows->sum('inconsistency_count')),
                'description' => 'Subset-scale quality diagnostics. Keep as internal reference in v1.',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildDailyQualityRows(Collection $rows): array
    {
        return $rows
            ->groupBy('day')
            ->map(function (Collection $group, string $day): array {
                $started = (int) $group->sum('started_attempts');
                $completed = (int) $group->sum('completed_attempts');
                $results = (int) $group->sum('results_count');
                $valid = (int) $group->sum('valid_results_count');

                return [
                    'day' => $day,
                    'started_attempts' => $started,
                    'completed_attempts' => $completed,
                    'results_count' => $results,
                    'completion_rate' => $started > 0 ? $completed / $started : null,
                    'validity_rate' => $results > 0 ? $valid / $results : null,
                    'quality_mix' => $this->qualityMixLabel(
                        (int) $group->sum('quality_a_count'),
                        (int) $group->sum('quality_b_count'),
                        (int) $group->sum('quality_c_count'),
                        (int) $group->sum('quality_d_count')
                    ),
                    'crisis_alert_count' => (int) $group->sum('crisis_alert_count'),
                    'warnings_count' => (int) $group->sum('warnings_count'),
                    'longstring_count' => (int) $group->sum('longstring_count'),
                    'straightlining_count' => (int) $group->sum('straightlining_count'),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildScaleQualityRows(Collection $rows): array
    {
        return $rows
            ->groupBy('scale_code')
            ->map(function (Collection $group, string $scaleCode): array {
                $started = (int) $group->sum('started_attempts');
                $completed = (int) $group->sum('completed_attempts');
                $results = (int) $group->sum('results_count');
                $valid = (int) $group->sum('valid_results_count');

                return [
                    'scale_code' => $scaleCode,
                    'results_count' => $results,
                    'completion_rate' => $started > 0 ? $completed / $started : null,
                    'validity_rate' => $results > 0 ? $valid / $results : null,
                    'quality_mix' => $this->qualityMixLabel(
                        (int) $group->sum('quality_a_count'),
                        (int) $group->sum('quality_b_count'),
                        (int) $group->sum('quality_c_count'),
                        (int) $group->sum('quality_d_count')
                    ),
                    'crisis_alert_count' => (int) $group->sum('crisis_alert_count'),
                    'longstring_count' => (int) $group->sum('longstring_count'),
                    'straightlining_count' => (int) $group->sum('straightlining_count'),
                    'extreme_count' => (int) $group->sum('extreme_count'),
                    'inconsistency_count' => (int) $group->sum('inconsistency_count'),
                    'version_mix' => $this->versionMixSummary($group),
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildQualityFlagRows(Collection $rows): array
    {
        $resultsCount = max(0, (int) $rows->sum('results_count'));
        $definitions = [
            'speeding_count' => ['label' => 'Speeding', 'reference' => 'Stable quality flag when present in result_json.'],
            'longstring_count' => ['label' => 'Longstring', 'reference' => 'Reference counter; stable on subset scales only.'],
            'straightlining_count' => ['label' => 'Straightlining', 'reference' => 'Reference counter; stable on subset scales only.'],
            'extreme_count' => ['label' => 'Extreme responding', 'reference' => 'Reference counter; EQ60 / BIG5 / subset-scale semantics only.'],
            'inconsistency_count' => ['label' => 'Inconsistency', 'reference' => 'Reference counter; subset-scale semantics only.'],
            'warnings_count' => ['label' => 'Warnings surfaced', 'reference' => 'Includes stable flags and report warnings where available.'],
        ];

        $rowsOut = [];
        foreach ($definitions as $field => $definition) {
            $count = (int) $rows->sum($field);
            $rowsOut[] = [
                'label' => $definition['label'],
                'count' => $count,
                'rate' => $resultsCount > 0 ? $count / $resultsCount : null,
                'reference' => $definition['reference'],
            ];
        }

        return $rowsOut;
    }

    /**
     * @return list<array<string,string>>
     */
    private function psychometricSourceDefinitions(): array
    {
        return [
            ['table' => 'big5_psychometrics_reports', 'scale_code' => 'BIG5_OCEAN'],
            ['table' => 'eq60_psychometrics_reports', 'scale_code' => 'EQ_60'],
            ['table' => 'sds_psychometrics_reports', 'scale_code' => 'SDS_20'],
        ];
    }

    /**
     * @param  array<string,string>  $filters
     */
    private function applyPsychometricFilters(QueryBuilder $query, array $filters): void
    {
        if (($filters['scale_code'] ?? 'all') !== 'all') {
            $query->where('scale_code', strtoupper((string) $filters['scale_code']));
        }
        if (($filters['locale'] ?? 'all') !== 'all') {
            $query->where('locale', (string) $filters['locale']);
        }
        if (($filters['region'] ?? 'all') !== 'all') {
            $query->where('region', (string) $filters['region']);
        }
        if (($filters['norm_version'] ?? 'all') !== 'all') {
            $query->where('norms_version', (string) $filters['norm_version']);
        }
    }

    private function psychometricSampleThreshold(string $scaleCode): int
    {
        return match (strtoupper(trim($scaleCode))) {
            'EQ_60' => max(1, (int) config('eq60_norms.psychometrics.min_samples', 100)),
            'SDS_20' => max(1, (int) config('sds_norms.psychometrics.min_samples', 100)),
            default => max(1, (int) config('big5_norms.psychometrics.min_samples', 100)),
        };
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{primary:string,secondary:string}
     */
    private function psychometricSummary(string $scaleCode, array $metrics): array
    {
        return match (strtoupper(trim($scaleCode))) {
            'EQ_60' => [
                'primary' => sprintf(
                    'Global std %.2f +/- %.2f',
                    (float) ($metrics['global_std_mean'] ?? 0.0),
                    (float) ($metrics['global_std_sd'] ?? 0.0)
                ),
                'secondary' => sprintf(
                    'Quality C+ %.1f%%',
                    100 * (float) ($metrics['quality_c_or_worse_rate'] ?? 0.0)
                ),
            ],
            'SDS_20' => [
                'primary' => sprintf(
                    'Index %.2f +/- %.2f',
                    (float) ($metrics['index_score_mean'] ?? 0.0),
                    (float) ($metrics['index_score_sd'] ?? 0.0)
                ),
                'secondary' => sprintf(
                    'Crisis %.1f%%',
                    100 * (float) ($metrics['crisis_rate'] ?? 0.0)
                ),
            ],
            default => $this->big5PsychometricSummary($metrics),
        };
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{primary:string,secondary:string}
     */
    private function big5PsychometricSummary(array $metrics): array
    {
        $domainAlpha = collect((array) ($metrics['domain_alpha'] ?? []))
            ->filter(static fn ($value): bool => is_numeric($value))
            ->map(static fn ($value): float => (float) $value)
            ->values();

        if ($domainAlpha->isEmpty()) {
            return [
                'primary' => 'Domain alpha n/a',
                'secondary' => 'Facet/item-total correlation stored in metrics_json',
            ];
        }

        return [
            'primary' => sprintf(
                'Domain alpha mean %.2f',
                (float) round((float) $domainAlpha->avg(), 2)
            ),
            'secondary' => sprintf(
                'Alpha range %.2f to %.2f',
                (float) round((float) $domainAlpha->min(), 2),
                (float) round((float) $domainAlpha->max(), 2)
            ),
        ];
    }

    /**
     * @param  array<string,string>  $filters
     * @param  list<string>  &$warnings
     * @return list<array<string,mixed>>
     */
    private function normCoverageRows(array $filters, array &$warnings): array
    {
        if (! SchemaBaseline::hasTable('scale_norms_versions')) {
            $warnings[] = 'scale_norms_versions is missing.';

            return [];
        }

        $versionRows = $this->normVersionBaseQuery($filters)
            ->where('is_active', 1)
            ->get([
                'id',
                'scale_code',
                'locale',
                'region',
                'version',
                'group_id',
                'status',
                'published_at',
                'updated_at',
            ]);

        if ($versionRows->isEmpty()) {
            return [];
        }

        $sampleByVersion = $this->maxSampleByNormVersion($versionRows->pluck('id')->map(static fn ($id): string => (string) $id)->all());

        return $versionRows
            ->groupBy(fn (object $row): string => implode('|', [
                strtoupper(trim((string) ($row->scale_code ?? 'UNKNOWN'))),
                $this->stringOrUnknown($row->locale ?? null),
                $this->stringOrUnknown($row->region ?? null),
                trim((string) ($row->version ?? '')),
            ]))
            ->map(function (Collection $group, string $key) use ($sampleByVersion): array {
                [$scaleCode, $locale, $region, $version] = explode('|', $key);
                $sampleMax = 0;
                foreach ($group as $row) {
                    $sampleMax = max($sampleMax, (int) ($sampleByVersion[(string) ($row->id ?? '')] ?? 0));
                }

                return [
                    'scale_code' => $scaleCode,
                    'locale' => $locale,
                    'region' => $region,
                    'active_norm_version' => $version !== '' ? $version : 'unknown',
                    'active_group_count' => $group->count(),
                    'coverage_sample_n' => $sampleMax,
                    'status_summary' => $group->pluck('status')->filter()->unique()->implode(', '),
                    'latest_published_at' => $this->formatDateTime($group->max('published_at')),
                    'latest_updated_at' => $this->formatDateTime($group->max('updated_at')),
                ];
            })
            ->sortBy([
                ['scale_code', 'asc'],
                ['locale', 'asc'],
                ['region', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  &$warnings
     * @return list<array<string,mixed>>
     */
    private function rolloutRows(int $orgId, array $filters, array &$warnings): array
    {
        if (! SchemaBaseline::hasTable('scoring_models') || ! SchemaBaseline::hasTable('scoring_model_rollouts')) {
            $warnings[] = 'scoring_models / scoring_model_rollouts are missing.';

            return [];
        }

        $orgScope = $orgId > 0 ? [0, $orgId] : [0];
        $models = DB::table('scoring_models')
            ->whereIn('org_id', $orgScope)
            ->when(($filters['scale_code'] ?? 'all') !== 'all', fn (QueryBuilder $query): QueryBuilder => $query->where('scale_code', strtoupper((string) $filters['scale_code'])))
            ->get([
                'id',
                'org_id',
                'scale_code',
                'model_key',
                'driver_type',
                'scoring_spec_version',
                'priority',
                'is_active',
            ]);

        $rollouts = DB::table('scoring_model_rollouts')
            ->whereIn('org_id', $orgScope)
            ->when(($filters['scale_code'] ?? 'all') !== 'all', fn (QueryBuilder $query): QueryBuilder => $query->where('scale_code', strtoupper((string) $filters['scale_code'])))
            ->orderBy('scale_code')
            ->orderBy('priority')
            ->get([
                'id',
                'org_id',
                'scale_code',
                'model_key',
                'experiment_key',
                'experiment_variant',
                'rollout_percent',
                'priority',
                'is_active',
                'starts_at',
                'ends_at',
                'created_at',
            ]);

        $resolvedModels = $this->resolvePreferredModels($models, $orgId);
        $observation = $this->rolloutObservationCoverage($orgId, $filters);
        $referencedModels = [];
        $rows = [];

        foreach ($rollouts as $rollout) {
            $scaleCode = strtoupper(trim((string) ($rollout->scale_code ?? 'UNKNOWN')));
            $modelKey = trim((string) ($rollout->model_key ?? ''));
            $resolvedModel = $resolvedModels[$scaleCode.'|'.$modelKey] ?? null;
            $rolloutId = trim((string) ($rollout->id ?? ''));
            $observedCount = $rolloutId !== ''
                ? (int) ($observation['by_rollout'][$rolloutId] ?? 0)
                : (int) ($observation['by_scale_model'][$scaleCode.'|'.$modelKey] ?? 0);
            $totalScaleResults = (int) ($observation['scale_totals'][$scaleCode] ?? 0);
            $referencedModels[$scaleCode.'|'.$modelKey] = true;

            $rows[] = [
                'scale_code' => $scaleCode,
                'model_key' => $modelKey !== '' ? $modelKey : 'unknown',
                'driver_type' => strtolower(trim((string) ($resolvedModel->driver_type ?? 'default'))) ?: 'default',
                'scoring_spec_version' => $this->stringOrUnknown($resolvedModel->scoring_spec_version ?? null),
                'rollout_rule' => $this->rolloutRuleLabel($rollout),
                'config_state' => $this->rolloutStateLabel($rollout),
                'priority' => max(0, (int) ($rollout->priority ?? 0)),
                'observation_results' => $observedCount,
                'observation_share' => $totalScaleResults > 0 ? $observedCount / $totalScaleResults : null,
                'observation_summary' => $totalScaleResults > 0
                    ? sprintf('%d of %d scoped results', $observedCount, $totalScaleResults)
                    : 'No scoped observations yet',
            ];
        }

        foreach ($resolvedModels as $key => $model) {
            if (isset($referencedModels[$key])) {
                continue;
            }

            $scaleCode = strtoupper(trim((string) ($model->scale_code ?? 'UNKNOWN')));
            $modelKey = trim((string) ($model->model_key ?? ''));
            $observedCount = (int) ($observation['by_scale_model'][$scaleCode.'|'.$modelKey] ?? 0);
            $totalScaleResults = (int) ($observation['scale_totals'][$scaleCode] ?? 0);

            $rows[] = [
                'scale_code' => $scaleCode,
                'model_key' => $modelKey !== '' ? $modelKey : 'unknown',
                'driver_type' => strtolower(trim((string) ($model->driver_type ?? 'default'))) ?: 'default',
                'scoring_spec_version' => $this->stringOrUnknown($model->scoring_spec_version ?? null),
                'rollout_rule' => 'No explicit rollout rule',
                'config_state' => ((int) ($model->is_active ?? 0) === 1) ? 'model-only' : 'inactive-model',
                'priority' => max(0, (int) ($model->priority ?? 0)),
                'observation_results' => $observedCount,
                'observation_share' => $totalScaleResults > 0 ? $observedCount / $totalScaleResults : null,
                'observation_summary' => $totalScaleResults > 0
                    ? sprintf('%d of %d scoped results', $observedCount, $totalScaleResults)
                    : 'No scoped observations yet',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $scaleCompare = strcmp((string) $a['scale_code'], (string) $b['scale_code']);
            if ($scaleCompare !== 0) {
                return $scaleCompare;
            }

            $priorityCompare = ((int) $a['priority']) <=> ((int) $b['priority']);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcmp((string) $a['model_key'], (string) $b['model_key']);
        });

        return $rows;
    }

    /**
     * @param  list<string>  &$warnings
     * @return list<array<string,mixed>>
     */
    private function driftCompareRows(array $filters, array &$warnings): array
    {
        if (! SchemaBaseline::hasTable('scale_norms_versions') || ! SchemaBaseline::hasTable('scale_norm_stats')) {
            $warnings[] = 'scale_norms_versions / scale_norm_stats are required for drift compare.';

            return [];
        }

        $versionRows = $this->normVersionBaseQuery($filters)
            ->orderBy('scale_code')
            ->orderBy('locale')
            ->orderBy('region')
            ->orderByDesc('is_active')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'scale_code',
                'locale',
                'region',
                'version',
                'group_id',
                'is_active',
                'published_at',
                'created_at',
            ]);

        if ($versionRows->isEmpty()) {
            return [];
        }

        $statsCache = [];
        $rows = [];

        foreach ($versionRows->groupBy(fn (object $row): string => implode('|', [
            strtoupper(trim((string) ($row->scale_code ?? 'UNKNOWN'))),
            $this->stringOrUnknown($row->locale ?? null),
            $this->stringOrUnknown($row->region ?? null),
        ])) as $groupKey => $group) {
            $activeVersion = $this->selectActiveNormVersion($group, $filters);
            if ($activeVersion === null) {
                continue;
            }

            $previousVersion = $this->selectPreviousNormVersion($group, $activeVersion);
            if ($previousVersion === null) {
                continue;
            }

            $activeRows = $group->where('version', $activeVersion)->values();
            $previousRows = $group->where('version', $previousVersion)->values();
            $activeByGroup = $activeRows->keyBy(fn (object $row): string => (string) ($row->group_id ?? ''));
            $previousByGroup = $previousRows->keyBy(fn (object $row): string => (string) ($row->group_id ?? ''));
            $commonGroups = array_values(array_intersect($activeByGroup->keys()->all(), $previousByGroup->keys()->all()));
            sort($commonGroups);

            $comparedMetrics = 0;
            $maxMeanDiff = 0.0;
            $maxSdDiff = 0.0;

            foreach ($commonGroups as $groupId) {
                $activeId = (string) ($activeByGroup[$groupId]->id ?? '');
                $previousId = (string) ($previousByGroup[$groupId]->id ?? '');
                $activeStats = $statsCache[$activeId] ??= $this->normStatsByVersion($activeId);
                $previousStats = $statsCache[$previousId] ??= $this->normStatsByVersion($previousId);
                $commonMetrics = array_intersect(array_keys($activeStats), array_keys($previousStats));

                foreach ($commonMetrics as $metricKey) {
                    $comparedMetrics++;
                    $maxMeanDiff = max(
                        $maxMeanDiff,
                        abs((float) ($activeStats[$metricKey]['mean'] ?? 0.0) - (float) ($previousStats[$metricKey]['mean'] ?? 0.0))
                    );
                    $maxSdDiff = max(
                        $maxSdDiff,
                        abs((float) ($activeStats[$metricKey]['sd'] ?? 0.0) - (float) ($previousStats[$metricKey]['sd'] ?? 0.0))
                    );
                }
            }

            if ($comparedMetrics <= 0) {
                continue;
            }

            [$scaleCode, $locale, $region] = explode('|', $groupKey);
            $rows[] = [
                'scale_code' => $scaleCode,
                'locale' => $locale,
                'region' => $region,
                'active_norm_version' => $activeVersion,
                'previous_norm_version' => $previousVersion,
                'compared_groups' => count($commonGroups),
                'compared_metrics' => $comparedMetrics,
                'max_mean_diff' => round($maxMeanDiff, 4),
                'max_sd_diff' => round($maxSdDiff, 4),
                'reference_state' => 'Internal compare reference',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,string>  $filters
     */
    private function normVersionBaseQuery(array $filters): QueryBuilder
    {
        $query = DB::table('scale_norms_versions');

        if (($filters['scale_code'] ?? 'all') !== 'all') {
            $query->where('scale_code', strtoupper((string) $filters['scale_code']));
        }
        if (($filters['locale'] ?? 'all') !== 'all') {
            $query->where('locale', (string) $filters['locale']);
        }
        if (($filters['region'] ?? 'all') !== 'all') {
            $query->where('region', (string) $filters['region']);
        }
        if (($filters['norm_version'] ?? 'all') !== 'all') {
            $query->where('version', (string) $filters['norm_version']);
        }

        return $query;
    }

    /**
     * @param  list<string>  $versionIds
     * @return array<string,int>
     */
    private function maxSampleByNormVersion(array $versionIds): array
    {
        if ($versionIds === [] || ! SchemaBaseline::hasTable('scale_norm_stats')) {
            return [];
        }

        return DB::table('scale_norm_stats')
            ->whereIn('norm_version_id', $versionIds)
            ->selectRaw('norm_version_id, MAX(sample_n) AS sample_n_max')
            ->groupBy('norm_version_id')
            ->pluck('sample_n_max', 'norm_version_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }

    /**
     * @param  Collection<int,object>  $models
     * @return array<string,object>
     */
    private function resolvePreferredModels(Collection $models, int $orgId): array
    {
        $resolved = [];

        foreach ($models->groupBy(fn (object $row): string => strtoupper(trim((string) ($row->scale_code ?? 'UNKNOWN'))).'|'.trim((string) ($row->model_key ?? ''))) as $key => $group) {
            $resolved[$key] = $group
                ->sort(function (object $a, object $b) use ($orgId): int {
                    $aOrgRank = ((int) ($a->org_id ?? 0) === $orgId) ? 0 : 1;
                    $bOrgRank = ((int) ($b->org_id ?? 0) === $orgId) ? 0 : 1;
                    if ($aOrgRank !== $bOrgRank) {
                        return $aOrgRank <=> $bOrgRank;
                    }

                    $aPriority = (int) ($a->priority ?? 100);
                    $bPriority = (int) ($b->priority ?? 100);
                    if ($aPriority !== $bPriority) {
                        return $aPriority <=> $bPriority;
                    }

                    return strcmp((string) ($a->id ?? ''), (string) ($b->id ?? ''));
                })
                ->first();
        }

        return $resolved;
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{
     *   scale_totals:array<string,int>,
     *   by_rollout:array<string,int>,
     *   by_scale_model:array<string,int>
     * }
     */
    private function rolloutObservationCoverage(int $orgId, array $filters): array
    {
        if (! SchemaBaseline::hasTable('attempts') || ! SchemaBaseline::hasTable('results')) {
            return [
                'scale_totals' => [],
                'by_rollout' => [],
                'by_scale_model' => [],
            ];
        }

        [$from, $to] = $this->resolvedRange($filters);

        $query = DB::table('results')
            ->join('attempts', 'attempts.id', '=', 'results.attempt_id')
            ->where('attempts.org_id', $orgId)
            ->whereNotNull('attempts.submitted_at')
            ->whereBetween('attempts.submitted_at', [$from->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->select([
                'attempts.scale_code',
                'attempts.locale',
                'attempts.region',
                'attempts.content_package_version',
                'attempts.scoring_spec_version',
                'attempts.norm_version',
                'results.result_json',
            ]);

        if (($filters['scale_code'] ?? 'all') !== 'all') {
            $query->where('attempts.scale_code', strtoupper((string) $filters['scale_code']));
        }
        if (($filters['locale'] ?? 'all') !== 'all') {
            $query->where('attempts.locale', (string) $filters['locale']);
        }
        if (($filters['region'] ?? 'all') !== 'all') {
            $query->where('attempts.region', (string) $filters['region']);
        }
        if (($filters['content_package_version'] ?? 'all') !== 'all') {
            $query->where('attempts.content_package_version', (string) $filters['content_package_version']);
        }
        if (($filters['scoring_spec_version'] ?? 'all') !== 'all') {
            $query->where('attempts.scoring_spec_version', (string) $filters['scoring_spec_version']);
        }
        if (($filters['norm_version'] ?? 'all') !== 'all') {
            $query->where('attempts.norm_version', (string) $filters['norm_version']);
        }

        $scaleTotals = [];
        $byRollout = [];
        $byScaleModel = [];

        foreach ($query->cursor() as $row) {
            $scaleCode = strtoupper(trim((string) ($row->scale_code ?? 'UNKNOWN')));
            $scaleTotals[$scaleCode] = (int) ($scaleTotals[$scaleCode] ?? 0) + 1;

            $payload = $this->decodeJson($row->result_json ?? null);
            $selection = is_array($payload['model_selection'] ?? null) ? $payload['model_selection'] : [];
            $modelKey = trim((string) ($selection['model_key'] ?? ''));
            $rolloutId = trim((string) ($selection['rollout_id'] ?? ''));

            if ($rolloutId !== '') {
                $byRollout[$rolloutId] = (int) ($byRollout[$rolloutId] ?? 0) + 1;
            }
            if ($modelKey !== '') {
                $compound = $scaleCode.'|'.$modelKey;
                $byScaleModel[$compound] = (int) ($byScaleModel[$compound] ?? 0) + 1;
            }
        }

        return [
            'scale_totals' => $scaleTotals,
            'by_rollout' => $byRollout,
            'by_scale_model' => $byScaleModel,
        ];
    }

    private function rolloutRuleLabel(object $rollout): string
    {
        $experimentKey = trim((string) ($rollout->experiment_key ?? ''));
        $experimentVariant = trim((string) ($rollout->experiment_variant ?? ''));
        $rolloutPercent = max(0, (int) ($rollout->rollout_percent ?? 0));

        if ($experimentKey === '') {
            return sprintf('Percent-only %d%%', $rolloutPercent);
        }

        if ($experimentVariant === '') {
            return sprintf('%s @ %d%%', $experimentKey, $rolloutPercent);
        }

        return sprintf('%s:%s @ %d%%', $experimentKey, $experimentVariant, $rolloutPercent);
    }

    private function rolloutStateLabel(object $rollout): string
    {
        if ((int) ($rollout->is_active ?? 0) !== 1) {
            return 'inactive';
        }

        $now = now();
        $startsAt = $this->normalizeCarbon($rollout->starts_at ?? null);
        $endsAt = $this->normalizeCarbon($rollout->ends_at ?? null);

        if ($startsAt !== null && $startsAt->greaterThan($now)) {
            return 'scheduled';
        }
        if ($endsAt !== null && $endsAt->lessThan($now)) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * @param  Collection<int,object>  $group
     * @param  array<string,string>  $filters
     */
    private function selectActiveNormVersion(Collection $group, array $filters): ?string
    {
        $selectedVersion = trim((string) ($filters['norm_version'] ?? ''));
        if ($selectedVersion !== '' && strtolower($selectedVersion) !== 'all') {
            $exact = $group->first(static fn (object $row): bool => trim((string) ($row->version ?? '')) === $selectedVersion);

            return $exact ? trim((string) ($exact->version ?? '')) : null;
        }

        $active = $group->first(static fn (object $row): bool => (int) ($row->is_active ?? 0) === 1);
        if ($active) {
            return trim((string) ($active->version ?? ''));
        }

        $latest = $group->first();

        return $latest ? trim((string) ($latest->version ?? '')) : null;
    }

    /**
     * @param  Collection<int,object>  $group
     */
    private function selectPreviousNormVersion(Collection $group, string $activeVersion): ?string
    {
        return $group
            ->filter(static fn (object $row): bool => trim((string) ($row->version ?? '')) !== $activeVersion)
            ->pluck('version')
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->first();
    }

    /**
     * @return array<string,array{mean:float,sd:float}>
     */
    private function normStatsByVersion(string $versionId): array
    {
        if ($versionId === '' || ! SchemaBaseline::hasTable('scale_norm_stats')) {
            return [];
        }

        return DB::table('scale_norm_stats')
            ->where('norm_version_id', $versionId)
            ->get(['metric_level', 'metric_code', 'mean', 'sd'])
            ->mapWithKeys(static function (object $row): array {
                $metricLevel = strtolower(trim((string) ($row->metric_level ?? '')));
                $metricCode = strtoupper(trim((string) ($row->metric_code ?? '')));
                if ($metricLevel === '' || $metricCode === '') {
                    return [];
                }

                return [
                    $metricLevel.':'.$metricCode => [
                        'mean' => (float) ($row->mean ?? 0.0),
                        'sd' => (float) ($row->sd ?? 0.0),
                    ],
                ];
            })
            ->all();
    }

    private function qualityMixLabel(int $a, int $b, int $c, int $d): string
    {
        $total = max(0, $a + $b + $c + $d);
        if ($total <= 0) {
            return 'n/a';
        }

        return sprintf(
            'A %s · B %s · C %s · D %s',
            $this->formatRate($a / $total),
            $this->formatRate($b / $total),
            $this->formatRate($c / $total),
            $this->formatRate($d / $total)
        );
    }

    private function versionMixSummary(Collection $rows): string
    {
        $top = $rows
            ->groupBy(fn (array $row): string => implode(' / ', [
                (string) $row['content_package_version'],
                (string) $row['scoring_spec_version'],
                (string) $row['norm_version'],
            ]))
            ->map(static fn (Collection $group): int => (int) $group->sum('results_count'))
            ->sortDesc()
            ->keys()
            ->first();

        return is_string($top) && trim($top) !== '' ? $top : 'n/a';
    }

    /**
     * @param  array<string,string>  $filters
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function resolvedRange(array $filters): array
    {
        $from = CarbonImmutable::parse(($filters['from'] ?? '') !== '' ? (string) $filters['from'] : now()->subDays(13)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse(($filters['to'] ?? '') !== '' ? (string) $filters['to'] : now()->toDateString())->startOfDay();

        return [$from, $to];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $raw): array
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

    private function stringOrUnknown(mixed $value): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function formatRate(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value * 100, 1).'%';
    }

    private function formatDateTime(mixed $value): string
    {
        $date = $this->normalizeCarbon($value);

        return $date?->format('Y-m-d H:i') ?? 'n/a';
    }

    private function normalizeCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($value));
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
