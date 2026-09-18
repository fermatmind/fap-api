<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccessTestStatisticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'access-test-statistics';

    protected static string $view = 'filament.ops.pages.access-test-statistics';

    public string $fromDate = '';

    public string $toDate = '';

    public string $scope = 'global_org0';

    public string $scaleCode = '*';

    public string $formCode = '*';

    public string $locale = '*';

    public array $scaleOptions = [];

    public array $formOptions = [];

    public array $localeOptions = [];

    public array $summary = [];

    public array $dailyRows = [];

    public array $testRows = [];

    public array $warnings = [];

    public array $meta = [];

    public function mount(): void
    {
        $today = CarbonImmutable::now('Asia/Shanghai');
        $this->fromDate = $today->toDateString();
        $this->toDate = $today->toDateString();
        $this->scope = request()->query('scope') === 'current_org' ? 'current_org' : 'global_org0';
        $this->refreshPage();
    }

    public static function canAccess(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.access_test_statistics');
    }

    public function getTitle(): string
    {
        return __('ops.nav.access_test_statistics');
    }

    public function applyFilters(): void
    {
        $this->refreshPage();
    }

    public function selectToday(): void
    {
        $today = CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $this->fromDate = $today;
        $this->toDate = $today;
        $this->refreshPage();
    }

    public function selectYesterday(): void
    {
        $yesterday = CarbonImmutable::now('Asia/Shanghai')->subDay()->toDateString();
        $this->fromDate = $yesterday;
        $this->toDate = $yesterday;
        $this->refreshPage();
    }

    public function selectRange(int $days): void
    {
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $today = CarbonImmutable::now('Asia/Shanghai');
        $this->fromDate = $today->subDays($days - 1)->toDateString();
        $this->toDate = $today->toDateString();
        $this->refreshPage();
    }

    public function refreshPage(): void
    {
        $this->warnings = [];
        $this->summary = [];
        $this->dailyRows = [];
        $this->testRows = [];
        $this->meta = [];

        if (! SchemaBaseline::hasTable('analytics_access_test_daily')) {
            $this->warnings[] = __('ops.pages.access_test_statistics.missing_read_model');

            return;
        }

        [$from, $to] = $this->range();
        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            $this->warnings[] = __('ops.pages.access_test_statistics.invalid_range');

            return;
        }

        $orgId = $this->orgId();
        if ($orgId < 0) {
            $this->warnings[] = __('ops.pages.access_test_statistics.select_org');

            return;
        }

        $this->loadOptions($orgId);
        $rows = $this->baseQuery($orgId)
            ->orderBy('day')
            ->get();

        if ($rows->isEmpty()) {
            $this->warnings[] = __('ops.pages.access_test_statistics.no_data');

            return;
        }

        $this->dailyRows = $rows->map(fn (object $row): array => $this->rowArray($row))->all();
        $this->summary = [
            'valid_visit_ips' => (int) $rows->sum('valid_visit_ips'),
            'started_test_ips' => (int) $rows->sum('started_test_ips'),
            'completed_test_ips' => (int) $rows->sum('completed_test_ips'),
            'successful_attempts' => (int) $rows->sum('successful_attempts'),
            'valid_page_views' => (int) $rows->sum('valid_page_views'),
        ];

        $lastRefresh = $rows->max('last_successful_refresh_at');
        $dataThrough = $rows->max('data_through_at');
        $statuses = $rows->pluck('coverage_status')->unique()->values()->all();
        $this->meta = [
            'timezone' => 'Asia/Shanghai',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'coverage' => implode(', ', $statuses),
            'data_through_at' => $dataThrough,
            'last_successful_refresh_at' => $lastRefresh,
            'source_version' => (string) ($rows->first()->source_version ?? ''),
            'is_multi_day' => $from->toDateString() !== $to->toDateString(),
        ];

        if ($to->isSameDay(CarbonImmutable::now('Asia/Shanghai'))
            && ($lastRefresh === null || CarbonImmutable::parse($lastRefresh)->lt(now()->subMinutes(15)))) {
            $this->warnings[] = __('ops.pages.access_test_statistics.stale');
        }

        $testRowsQuery = DB::table('analytics_access_test_daily')
            ->where('org_id', $orgId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('form_code', $this->formCode)
            ->where('locale', $this->locale);
        if ($this->scaleCode === '*') {
            $testRowsQuery->where('scale_code', '<>', '*');
        } else {
            $testRowsQuery->where('scale_code', $this->scaleCode);
        }

        $this->testRows = $testRowsQuery
            ->orderByDesc('day')
            ->orderBy('scale_code')
            ->limit(500)
            ->get()
            ->map(fn (object $row): array => $this->rowArray($row))
            ->all();
    }

    public function exportCsv(): StreamedResponse
    {
        $this->refreshPage();
        $rows = $this->dailyRows;
        $scaleCode = $this->scaleCode;
        $formCode = $this->formCode;
        $locale = $this->locale;
        $filename = 'access-test-statistics-'.$this->fromDate.'-'.$this->toDate.'.csv';

        return response()->streamDownload(function () use ($rows, $scaleCode, $formCode, $locale): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['day', 'timezone', 'scale_code', 'form_code', 'locale', 'valid_visit_ips', 'started_test_ips', 'completed_test_ips', 'successful_attempts', 'valid_page_views', 'coverage_status', 'data_through_at', 'last_successful_refresh_at', 'source_version']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['day'], 'Asia/Shanghai', $scaleCode, $formCode, $locale,
                    $row['valid_visit_ips'], $row['started_test_ips'], $row['completed_test_ips'],
                    $row['successful_attempts'], $row['valid_page_views'], $row['coverage_status'],
                    $row['data_through_at'], $row['last_successful_refresh_at'], $row['source_version'],
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function formatInt(int $value): string
    {
        return number_format($value);
    }

    private function baseQuery(int $orgId): Builder
    {
        [$from, $to] = $this->range();

        return DB::table('analytics_access_test_daily')
            ->where('org_id', $orgId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('scale_code', $this->scaleCode)
            ->where('form_code', $this->formCode)
            ->where('locale', $this->locale);
    }

    private function loadOptions(int $orgId): void
    {
        $this->scaleOptions = $this->options('scale_code', $orgId);
        $this->formOptions = $this->options('form_code', $orgId);
        $this->localeOptions = $this->options('locale', $orgId);
    }

    private function options(string $column, int $orgId): array
    {
        return DB::table('analytics_access_test_daily')
            ->where('org_id', $orgId)
            ->where($column, '<>', '*')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }

    private function orgId(): int
    {
        if ($this->scope === 'global_org0') {
            return 0;
        }
        $orgId = (int) app(OrgContext::class)->orgId();

        return $orgId > 0 ? $orgId : -1;
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function range(): array
    {
        return [
            CarbonImmutable::parse($this->fromDate, 'Asia/Shanghai')->startOfDay(),
            CarbonImmutable::parse($this->toDate, 'Asia/Shanghai')->startOfDay(),
        ];
    }

    private function rowArray(object $row): array
    {
        return [
            'day' => (string) $row->day,
            'scale_code' => (string) $row->scale_code,
            'form_code' => (string) $row->form_code,
            'locale' => (string) $row->locale,
            'valid_visit_ips' => (int) $row->valid_visit_ips,
            'started_test_ips' => (int) $row->started_test_ips,
            'completed_test_ips' => (int) $row->completed_test_ips,
            'successful_attempts' => (int) $row->successful_attempts,
            'valid_page_views' => (int) $row->valid_page_views,
            'missing_visit_ip_events' => (int) $row->missing_visit_ip_events,
            'missing_started_ip_attempts' => (int) $row->missing_started_ip_attempts,
            'missing_completed_ip_attempts' => (int) $row->missing_completed_ip_attempts,
            'excluded_page_views' => (int) $row->excluded_page_views,
            'excluded_started_attempts' => (int) $row->excluded_started_attempts,
            'excluded_completed_attempts' => (int) $row->excluded_completed_attempts,
            'suspected_page_views' => (int) $row->suspected_page_views,
            'suspected_attempts' => (int) $row->suspected_attempts,
            'coverage_status' => (string) $row->coverage_status,
            'data_through_at' => (string) ($row->data_through_at ?? ''),
            'last_successful_refresh_at' => (string) $row->last_successful_refresh_at,
            'source_version' => (string) $row->source_version,
        ];
    }
}
