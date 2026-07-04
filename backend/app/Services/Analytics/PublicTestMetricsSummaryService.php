<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class PublicTestMetricsSummaryService
{
    private const GLOBAL_ORG_ID = 0;

    /**
     * @return array<string,mixed>
     */
    public function summarize(): array
    {
        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            return [
                'available' => false,
                'org_id' => self::GLOBAL_ORG_ID,
                'row_count' => 0,
                'cumulative_successful_attempts' => 0,
                'cumulative_failed_attempts' => 0,
                'source' => 'analytics_test_metrics_daily',
            ];
        }

        $row = DB::table('analytics_test_metrics_daily')
            ->where('org_id', self::GLOBAL_ORG_ID)
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COALESCE(SUM(successful_attempts), 0) as cumulative_successful_attempts')
            ->selectRaw('COALESCE(SUM(failed_attempts), 0) as cumulative_failed_attempts')
            ->first();

        $rowCount = (int) ($row->row_count ?? 0);

        return [
            'available' => $rowCount > 0,
            'org_id' => self::GLOBAL_ORG_ID,
            'row_count' => $rowCount,
            'cumulative_successful_attempts' => (int) ($row->cumulative_successful_attempts ?? 0),
            'cumulative_failed_attempts' => (int) ($row->cumulative_failed_attempts ?? 0),
            'source' => 'analytics_test_metrics_daily',
        ];
    }
}
