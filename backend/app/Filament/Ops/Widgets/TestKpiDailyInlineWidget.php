<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\SchemaBaseline;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TestKpiDailyInlineWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static string $view = 'filament.ops.widgets.test-kpi-daily-inline-widget';

    protected int | string | array $columnSpan = 'full';

    private const GLOBAL_ORG_ID = 0;

    private const ROW_LIMIT = 12;

    public static function canView(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    /**
     * @return array<string,mixed>
     */
    protected function getViewData(): array
    {
        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            return [
                'rows' => [],
                'warning' => __('ops.pages.test_kpi_daily.missing_read_model'),
            ];
        }

        $rows = DB::table('analytics_test_metrics_daily')
            ->where('org_id', self::GLOBAL_ORG_ID)
            ->where('day', now()->toDateString())
            ->select([
                'day',
                'scale_code',
                'scale_code_v2',
                'form_code',
                'locale',
            ])
            ->selectRaw('SUM(started_attempts) as started_attempts')
            ->selectRaw('SUM(successful_attempts) as successful_attempts')
            ->selectRaw('SUM(failed_attempts) as failed_attempts')
            ->selectRaw('SUM(total_attempts) as total_attempts')
            ->groupBy('day', 'scale_code', 'scale_code_v2', 'form_code', 'locale')
            ->orderByDesc('total_attempts')
            ->orderBy('scale_code')
            ->orderBy('form_code')
            ->orderBy('locale')
            ->limit(self::ROW_LIMIT)
            ->get();

        return [
            'rows' => $this->formatRows($rows),
            'warning' => $rows->isEmpty()
                ? __('ops.widgets.no_test_kpi_daily_rows')
                : null,
        ];
    }

    public function formatInt(int $value): string
    {
        return number_format($value);
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return list<array<string,mixed>>
     */
    private function formatRows(Collection $rows): array
    {
        return $rows
            ->map(static function (object $row): array {
                return [
                    'day' => trim((string) ($row->day ?? '')),
                    'scale_code' => trim((string) ($row->scale_code ?? 'unknown')),
                    'scale_code_v2' => trim((string) ($row->scale_code_v2 ?? '')),
                    'form_code' => trim((string) ($row->form_code ?? '')),
                    'locale' => trim((string) ($row->locale ?? 'unknown')),
                    'started_attempts' => (int) ($row->started_attempts ?? 0),
                    'successful_attempts' => (int) ($row->successful_attempts ?? 0),
                    'failed_attempts' => (int) ($row->failed_attempts ?? 0),
                    'total_attempts' => (int) ($row->total_attempts ?? 0),
                ];
            })
            ->all();
    }
}
