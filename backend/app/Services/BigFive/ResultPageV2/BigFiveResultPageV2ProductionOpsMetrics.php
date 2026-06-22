<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Support\SchemaBaseline;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BigFiveResultPageV2ProductionOpsMetrics
{
    private const TABLE = 'report_snapshots';

    private const REQUIRED_COLUMNS = [
        'scale_code',
        'big5_result_page_v2_status',
        'big5_result_page_v2_fallback_reason',
        'big5_result_page_v2_validation_error_count',
        'big5_result_page_v2_audited_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string,mixed>
     */
    public function summarize(int $windowDays = 45): array
    {
        $windowDays = max(1, $windowDays);
        $missingColumns = $this->missingRequiredColumns();

        if (! SchemaBaseline::hasTable(self::TABLE) || $missingColumns !== []) {
            return $this->blockedSummary($windowDays, $missingColumns);
        }

        $statusRows = $this->baseQuery($windowDays)
            ->selectRaw("lower(coalesce(big5_result_page_v2_status, '')) as v2_status, count(*) as aggregate")
            ->groupBy('v2_status')
            ->pluck('aggregate', 'v2_status')
            ->mapWithKeys(fn ($count, $status): array => [(string) $status => max(0, (int) $count)])
            ->all();

        $total = array_sum(array_map('intval', $statusRows));
        $attached = (int) ($statusRows['attached'] ?? 0);
        $fallback = (int) ($statusRows['fallback'] ?? 0);
        $invalid = (int) ($statusRows['invalid'] ?? 0);
        $disabledOrNotEvaluated = max(0, $total - $attached - $fallback - $invalid);

        $reasonRows = $this->baseQuery($windowDays)
            ->selectRaw(
                "lower(coalesce(big5_result_page_v2_status, '')) as v2_status, ".
                "lower(coalesce(big5_result_page_v2_fallback_reason, '')) as fallback_reason, ".
                'count(*) as aggregate'
            )
            ->whereIn(DB::raw("lower(coalesce(big5_result_page_v2_status, ''))"), ['fallback', 'invalid'])
            ->groupBy('v2_status', 'fallback_reason')
            ->get();

        $fallbackReasons = [];
        $malformedReasons = [];
        foreach ($reasonRows as $row) {
            $status = strtolower(trim((string) ($row->v2_status ?? '')));
            $reason = strtolower(trim((string) ($row->fallback_reason ?? ''))) ?: 'unspecified';
            $count = max(0, (int) ($row->aggregate ?? 0));

            if ($status === 'fallback') {
                $fallbackReasons[$reason] = ($fallbackReasons[$reason] ?? 0) + $count;
            }

            if ($status === 'invalid') {
                $malformedReasons[$reason] = ($malformedReasons[$reason] ?? 0) + $count;
            }
        }

        ksort($fallbackReasons);
        ksort($malformedReasons);

        $validationErrorCount = (int) $this->baseQuery($windowDays)
            ->sum('big5_result_page_v2_validation_error_count');
        $latestAuditedAt = $this->latestAuditedAt($windowDays);

        return [
            'source' => 'report_snapshots',
            'query_status' => 'ready',
            'blockers' => [],
            'reporting_window_days' => $windowDays,
            'metrics' => [
                'total_big5_reports' => $total,
                'attached_count' => $attached,
                'fallback_count' => $fallback,
                'invalid_count' => $invalid,
                'disabled_or_not_evaluated_count' => $disabledOrNotEvaluated,
                'v2_payload_coverage_rate' => $this->formatRate($attached, $total),
                'fallback_hit_rate' => $this->formatRate($fallback + $invalid, $total),
                'malformed_rejection_reasons' => $malformedReasons,
                'fallback_reasons' => $fallbackReasons,
                'validation_error_count' => max(0, $validationErrorCount),
                'latest_audited_at' => $latestAuditedAt,
                'status_counts' => $this->sortCounts($statusRows),
            ],
            'redaction' => $this->redactionPolicy(),
        ];
    }

    /**
     * @return list<string>
     */
    private function missingRequiredColumns(): array
    {
        if (! SchemaBaseline::hasTable(self::TABLE)) {
            return ['report_snapshots'];
        }

        return array_values(array_filter(
            self::REQUIRED_COLUMNS,
            static fn (string $column): bool => ! SchemaBaseline::hasColumn(self::TABLE, $column),
        ));
    }

    private function baseQuery(int $windowDays): QueryBuilder
    {
        return DB::table(self::TABLE)
            ->whereRaw("upper(coalesce(scale_code, '')) = ?", ['BIG5_OCEAN'])
            ->where(function (QueryBuilder $builder) use ($windowDays): void {
                $this->applyWindow($builder, $windowDays);
            });
    }

    private function applyWindow(QueryBuilder $query, int $windowDays): void
    {
        $start = now()->subDays($windowDays);

        $query
            ->where(self::TABLE.'.updated_at', '>=', $start)
            ->orWhere(function (QueryBuilder $builder) use ($start): void {
                $builder
                    ->whereNull(self::TABLE.'.updated_at')
                    ->where(self::TABLE.'.created_at', '>=', $start);
            });
    }

    private function latestAuditedAt(int $windowDays): ?string
    {
        $value = $this->baseQuery($windowDays)->max('big5_result_page_v2_audited_at');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toISOString();
    }

    private function formatRate(int $numerator, int $denominator): string
    {
        if ($denominator <= 0) {
            return '0.0%';
        }

        return number_format(($numerator / $denominator) * 100, 1).'%';
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<string,int>
     */
    private function sortCounts(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<string>  $missingColumns
     * @return array<string,mixed>
     */
    private function blockedSummary(int $windowDays, array $missingColumns): array
    {
        return [
            'source' => 'report_snapshots',
            'query_status' => 'blocked',
            'blockers' => [
                [
                    'code' => SchemaBaseline::hasTable(self::TABLE) ? 'missing_required_columns' : 'missing_report_snapshots_table',
                    'missing_columns' => $missingColumns,
                ],
            ],
            'reporting_window_days' => $windowDays,
            'metrics' => [
                'total_big5_reports' => 0,
                'attached_count' => 0,
                'fallback_count' => 0,
                'invalid_count' => 0,
                'disabled_or_not_evaluated_count' => 0,
                'v2_payload_coverage_rate' => '0.0%',
                'fallback_hit_rate' => '0.0%',
                'malformed_rejection_reasons' => [],
                'fallback_reasons' => [],
                'validation_error_count' => 0,
                'latest_audited_at' => null,
                'status_counts' => [],
            ],
            'redaction' => $this->redactionPolicy(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function redactionPolicy(): array
    {
        return [
            'identity_fields' => 'not_returned',
            'private_access_fields' => 'not_returned',
            'pdf_binary_fields' => 'not_returned',
            'report_body_fields' => 'not_returned',
            'score_fields' => 'not_returned',
            'metric_values' => 'count_rate_enum_timestamp_only',
        ];
    }
}
