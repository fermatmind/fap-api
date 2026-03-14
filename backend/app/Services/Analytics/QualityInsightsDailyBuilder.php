<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class QualityInsightsDailyBuilder
{
    public function __construct(
        private readonly QualitySignalExtractor $signalExtractor,
    ) {}

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   attempted_rows:int,
     *   source_started_attempts:int,
     *   source_completed_attempts:int,
     *   source_results:int,
     *   org_scope:list<int>,
     *   scale_scope:list<string>,
     *   locale_scope:list<string>,
     *   from:string,
     *   to:string
     * }
     */
    public function build(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
        array $locales = [],
    ): array {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $normalizedScaleCodes = $this->normalizeScaleCodes($scaleCodes);
        $normalizedLocales = $this->normalizeLocales($locales);

        if (! SchemaBaseline::hasTable('attempts')) {
            return [
                'rows' => [],
                'attempted_rows' => 0,
                'source_started_attempts' => 0,
                'source_completed_attempts' => 0,
                'source_results' => 0,
                'org_scope' => $normalizedOrgIds,
                'scale_scope' => $normalizedScaleCodes,
                'locale_scope' => $normalizedLocales,
                'from' => $fromAt->toDateString(),
                'to' => $toAt->toDateString(),
            ];
        }

        $aggregates = [];
        $sourceStartedAttempts = 0;
        $sourceCompletedAttempts = 0;
        $sourceResults = 0;
        $now = now();

        foreach ($this->startedAttemptCursor($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes, $normalizedLocales) as $row) {
            $sourceStartedAttempts++;
            $dimensions = $this->dimensionPayload(
                $row,
                $this->resolveDateString($row->started_at ?? null, $row->created_at ?? null)
            );
            $key = $this->aggregateKey($dimensions);

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = $this->baseAggregateRow($dimensions, $now);
            }

            $aggregates[$key]['started_attempts']++;
        }

        foreach ($this->completedAttemptCursor($fromAt, $toAt, $normalizedOrgIds, $normalizedScaleCodes, $normalizedLocales) as $row) {
            $sourceCompletedAttempts++;
            $dimensions = $this->dimensionPayload(
                $row,
                $this->resolveDateString($row->submitted_at ?? null, $row->created_at ?? null)
            );
            $key = $this->aggregateKey($dimensions);

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = $this->baseAggregateRow($dimensions, $now);
            }

            $aggregates[$key]['completed_attempts']++;

            if (! filled($row->result_id ?? null)) {
                continue;
            }

            $sourceResults++;
            $aggregates[$key]['results_count']++;

            $signal = $this->signalExtractor->extract(
                $row->result_json ?? null,
                $row->calculation_snapshot_json ?? null,
                $row->result_is_valid ?? null
            );

            $validityBucket = (string) ($signal['validity_bucket'] ?? 'unknown');
            if ($validityBucket === 'valid') {
                $aggregates[$key]['valid_results_count']++;
            } elseif ($validityBucket === 'invalid') {
                $aggregates[$key]['invalid_results_count']++;
            }

            $level = (string) ($signal['level'] ?? '');
            if ($level === 'A') {
                $aggregates[$key]['quality_a_count']++;
            } elseif ($level === 'B') {
                $aggregates[$key]['quality_b_count']++;
            } elseif ($level === 'C') {
                $aggregates[$key]['quality_c_count']++;
            } elseif ($level === 'D') {
                $aggregates[$key]['quality_d_count']++;
            }

            if (($signal['crisis_alert'] ?? false) === true) {
                $aggregates[$key]['crisis_alert_count']++;
            }

            $flags = array_flip((array) ($signal['flags'] ?? []));
            if (isset($flags['SPEEDING'])) {
                $aggregates[$key]['speeding_count']++;
            }
            if (isset($flags['LONGSTRING'])) {
                $aggregates[$key]['longstring_count']++;
            }
            if (isset($flags['STRAIGHTLINING'])) {
                $aggregates[$key]['straightlining_count']++;
            }
            if (isset($flags['EXTREME_RESPONDING']) || isset($flags['EXTREME_RESPONSE_BIAS'])) {
                $aggregates[$key]['extreme_count']++;
            }
            if (isset($flags['INCONSISTENT']) || isset($flags['INCONSISTENCY'])) {
                $aggregates[$key]['inconsistency_count']++;
            }
            if (($signal['has_warning_signal'] ?? false) === true) {
                $aggregates[$key]['warnings_count']++;
            }
        }

        return [
            'rows' => array_values($aggregates),
            'attempted_rows' => count($aggregates),
            'source_started_attempts' => $sourceStartedAttempts,
            'source_completed_attempts' => $sourceCompletedAttempts,
            'source_results' => $sourceResults,
            'org_scope' => $normalizedOrgIds,
            'scale_scope' => $normalizedScaleCodes,
            'locale_scope' => $normalizedLocales,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    public function refresh(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
        array $locales = [],
        bool $dryRun = false,
    ): array {
        $payload = $this->build($from, $to, $orgIds, $scaleCodes, $locales);
        $deletedRows = 0;
        $upsertedRows = 0;

        if (! $dryRun && SchemaBaseline::hasTable('analytics_scale_quality_daily')) {
            DB::transaction(function () use ($payload, &$deletedRows, &$upsertedRows): void {
                $deletedRows = $this->deleteScope(
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['scale_scope'],
                    $payload['locale_scope']
                );

                if ($payload['rows'] === []) {
                    return;
                }

                DB::table('analytics_scale_quality_daily')->upsert(
                    $payload['rows'],
                    [
                        'day',
                        'org_id',
                        'scale_code',
                        'locale',
                        'region',
                        'content_package_version',
                        'scoring_spec_version',
                        'norm_version',
                    ],
                    [
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
                        'updated_at',
                    ]
                );
                $upsertedRows = count($payload['rows']);
            });
        }

        return $payload + [
            'deleted_rows' => $deletedRows,
            'upserted_rows' => $upsertedRows,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     */
    private function startedAttemptCursor(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $scaleCodes,
        array $locales,
    ): \Generator {
        $query = DB::table('attempts')
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$fromAt->toDateTimeString()])
            ->whereRaw('COALESCE(started_at, created_at) <= ?', [$toAt->toDateTimeString()])
            ->orderBy('id')
            ->select([
                'id',
                'org_id',
                'scale_code',
                'scale_code_v2',
                'locale',
                'region',
                'content_package_version',
                'dir_version',
                'pack_id',
                'scoring_spec_version',
                'norm_version',
                'started_at',
                'created_at',
            ]);

        $this->applyAttemptFilters($query, $orgIds, $scaleCodes, $locales);

        foreach ($query->cursor() as $row) {
            yield $row;
        }
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     */
    private function completedAttemptCursor(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $scaleCodes,
        array $locales,
    ): \Generator {
        $query = DB::table('attempts')
            ->leftJoin('results', 'results.attempt_id', '=', 'attempts.id')
            ->whereNotNull('attempts.submitted_at')
            ->where('attempts.submitted_at', '>=', $fromAt->toDateTimeString())
            ->where('attempts.submitted_at', '<=', $toAt->toDateTimeString())
            ->orderBy('attempts.id')
            ->select([
                'attempts.id',
                'attempts.org_id',
                'attempts.scale_code',
                'attempts.scale_code_v2',
                'attempts.locale',
                'attempts.region',
                'attempts.content_package_version',
                'attempts.dir_version',
                'attempts.pack_id',
                'attempts.scoring_spec_version',
                'attempts.norm_version',
                'attempts.submitted_at',
                'attempts.created_at',
                'attempts.calculation_snapshot_json',
                'results.id as result_id',
                'results.result_json',
                'results.is_valid as result_is_valid',
            ]);

        $this->applyAttemptFilters($query, $orgIds, $scaleCodes, $locales);

        foreach ($query->cursor() as $row) {
            yield $row;
        }
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     */
    private function applyAttemptFilters(\Illuminate\Database\Query\Builder $query, array $orgIds, array $scaleCodes, array $locales): void
    {
        if ($orgIds !== []) {
            $query->whereIn('attempts.org_id', $orgIds);
        }
        if ($scaleCodes !== []) {
            $query->whereIn('attempts.scale_code', $scaleCodes);
        }
        if ($locales !== []) {
            $query->whereIn('attempts.locale', $locales);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function dimensionPayload(object $row, string $day): array
    {
        $contentPackageVersion = trim((string) ($row->content_package_version ?? ''));
        if ($contentPackageVersion === '') {
            $contentPackageVersion = trim((string) ($row->dir_version ?? ($row->pack_id ?? '')));
        }

        return [
            'day' => $day,
            'org_id' => max(0, (int) ($row->org_id ?? 0)),
            'scale_code' => $this->normalizeScaleCode((string) ($row->scale_code ?? ''), (string) ($row->scale_code_v2 ?? '')),
            'locale' => $this->stringOrUnknown($row->locale ?? null),
            'region' => $this->stringOrUnknown($row->region ?? null),
            'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : 'unknown',
            'scoring_spec_version' => $this->stringOrUnknown($row->scoring_spec_version ?? null),
            'norm_version' => $this->stringOrUnknown($row->norm_version ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array<string,mixed>
     */
    private function baseAggregateRow(array $dimensions, \DateTimeInterface $now): array
    {
        return [
            'day' => $dimensions['day'],
            'org_id' => $dimensions['org_id'],
            'scale_code' => $dimensions['scale_code'],
            'locale' => $dimensions['locale'],
            'region' => $dimensions['region'],
            'content_package_version' => $dimensions['content_package_version'],
            'scoring_spec_version' => $dimensions['scoring_spec_version'],
            'norm_version' => $dimensions['norm_version'],
            'started_attempts' => 0,
            'completed_attempts' => 0,
            'results_count' => 0,
            'valid_results_count' => 0,
            'invalid_results_count' => 0,
            'quality_a_count' => 0,
            'quality_b_count' => 0,
            'quality_c_count' => 0,
            'quality_d_count' => 0,
            'crisis_alert_count' => 0,
            'speeding_count' => 0,
            'longstring_count' => 0,
            'straightlining_count' => 0,
            'extreme_count' => 0,
            'inconsistency_count' => 0,
            'warnings_count' => 0,
            'last_refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,mixed>  $dimensions
     */
    private function aggregateKey(array $dimensions): string
    {
        return implode('|', [
            (string) $dimensions['day'],
            (string) $dimensions['org_id'],
            (string) $dimensions['scale_code'],
            (string) $dimensions['locale'],
            (string) $dimensions['region'],
            (string) $dimensions['content_package_version'],
            (string) $dimensions['scoring_spec_version'],
            (string) $dimensions['norm_version'],
        ]);
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     */
    private function deleteScope(string $from, string $to, array $orgIds, array $scaleCodes, array $locales): int
    {
        $query = DB::table('analytics_scale_quality_daily')
            ->whereBetween('day', [$from, $to]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }
        if ($scaleCodes !== []) {
            $query->whereIn('scale_code', $scaleCodes);
        }
        if ($locales !== []) {
            $query->whereIn('locale', $locales);
        }

        return $query->delete();
    }

    private function resolveDateString(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            return CarbonImmutable::parse($value)->toDateString();
        }

        return now()->toDateString();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $normalized = [];
        foreach ($orgIds as $orgId) {
            $value = max(0, (int) $orgId);
            if ($value > 0) {
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

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $value = trim((string) $locale);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeScaleCode(string $scaleCode, string $scaleCodeV2): string
    {
        $primary = strtoupper(trim($scaleCode));
        if ($primary !== '') {
            return $primary;
        }

        $secondary = strtoupper(trim($scaleCodeV2));

        return $secondary !== '' ? $secondary : 'UNKNOWN';
    }

    private function stringOrUnknown(mixed $value): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : 'unknown';
    }
}
