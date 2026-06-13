<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TestKpiDailyPage extends Page
{
    private const SCOPE_CURRENT_ORG = 'current_org';

    private const SCOPE_GLOBAL_ORG0 = 'global_org0';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'test-kpi-daily';

    protected static string $view = 'filament.ops.pages.test-kpi-daily-page';

    public string $fromDate = '';

    public string $toDate = '';

    public string $scope = self::SCOPE_CURRENT_ORG;

    public string $scaleCode = 'all';

    public string $formCode = 'all';

    public string $locale = 'all';

    /** @var array<string,string> */
    public array $scaleOptions = [];

    /** @var array<string,string> */
    public array $formOptions = [];

    /** @var array<string,string> */
    public array $localeOptions = [];

    /** @var list<array<string,mixed>> */
    public array $kpis = [];

    /** @var list<array<string,mixed>> */
    public array $dailyRows = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $hasData = false;

    public function mount(): void
    {
        $this->fromDate = now()->subDays(13)->toDateString();
        $this->toDate = now()->toDateString();
        $this->scope = $this->normalizeScope(request()->query('scope'));
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
        return __('ops.nav.test_kpi_daily');
    }

    public function getTitle(): string
    {
        return __('ops.nav.test_kpi_daily');
    }

    public function getSubheading(): ?string
    {
        return __('ops.pages.test_kpi_daily.subheading');
    }

    public function applyFilters(): void
    {
        $this->scope = $this->normalizeScope($this->scope);
        $this->refreshPage();
    }

    public function refreshPage(): void
    {
        $this->warnings = [];
        $this->hasData = false;
        $this->kpis = [];
        $this->dailyRows = [];
        $this->loadFilterOptions();

        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            $this->warnings[] = __('ops.pages.test_kpi_daily.missing_read_model');

            return;
        }

        [$from, $to] = $this->resolvedRange();
        if ($from->greaterThan($to)) {
            $this->warnings[] = __('ops.pages.test_kpi_daily.invalid_range');

            return;
        }

        $orgId = $this->currentOrgId();
        if ($orgId < 0) {
            $this->warnings[] = __('ops.pages.test_kpi_daily.select_org_first');

            return;
        }

        $rows = $this->scopedQuery()
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
            ->selectRaw('MAX(last_refreshed_at) as last_refreshed_at')
            ->groupBy('day', 'scale_code', 'scale_code_v2', 'form_code', 'locale')
            ->orderByDesc('day')
            ->orderBy('scale_code')
            ->orderBy('form_code')
            ->orderBy('locale')
            ->limit(500)
            ->get();

        $this->hasData = $rows->isNotEmpty();

        if (! $this->hasData) {
            $this->warnings[] = __('ops.pages.test_kpi_daily.no_rows', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

            return;
        }

        $this->dailyRows = $this->formatRows($rows);
        $this->kpis = $this->buildKpis($rows);
    }

    public function formatInt(int $value): string
    {
        return number_format($value);
    }

    public function formatRate(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value * 100, 1).'%';
    }

    private function loadFilterOptions(): void
    {
        $this->scaleOptions = [];
        $this->formOptions = [];
        $this->localeOptions = [];

        if (! SchemaBaseline::hasTable('analytics_test_metrics_daily')) {
            return;
        }

        $orgId = $this->currentOrgId();
        if ($orgId < 0) {
            return;
        }

        $this->scaleOptions = $this->distinctOptions('scale_code', $orgId);
        $this->formOptions = $this->distinctOptions('form_code', $orgId, __('ops.pages.test_kpi_daily.default_form'));
        $this->localeOptions = $this->distinctOptions('locale', $orgId);
    }

    /**
     * @return array<string,string>
     */
    private function distinctOptions(string $column, int $orgId, ?string $emptyLabel = null): array
    {
        return DB::table('analytics_test_metrics_daily')
            ->where('org_id', $orgId)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->mapWithKeys(function (mixed $value) use ($emptyLabel): array {
                $normalized = trim((string) $value);
                if ($normalized === '') {
                    return $emptyLabel === null ? [] : ['__empty__' => $emptyLabel];
                }

                return [$normalized => $normalized];
            })
            ->all();
    }

    /**
     * @return array{CarbonImmutable,CarbonImmutable}
     */
    private function resolvedRange(): array
    {
        return [
            CarbonImmutable::parse($this->fromDate)->startOfDay(),
            CarbonImmutable::parse($this->toDate)->endOfDay(),
        ];
    }

    private function scopedQuery(): Builder
    {
        [$from, $to] = $this->resolvedRange();

        $query = DB::table('analytics_test_metrics_daily')
            ->where('org_id', $this->currentOrgId())
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        if ($this->scaleCode !== 'all') {
            $query->where('scale_code', $this->scaleCode);
        }

        if ($this->formCode !== 'all') {
            $this->formCode === '__empty__'
                ? $query->where('form_code', '')
                : $query->where('form_code', $this->formCode);
        }

        if ($this->locale !== 'all') {
            $query->where('locale', $this->locale);
        }

        return $query;
    }

    private function currentOrgId(): int
    {
        if ($this->scope === self::SCOPE_GLOBAL_ORG0) {
            return 0;
        }

        $orgId = (int) app(OrgContext::class)->orgId();

        return $orgId > 0 ? $orgId : -1;
    }

    private function normalizeScope(mixed $scope): string
    {
        $normalized = strtolower(trim((string) $scope));

        return match ($normalized) {
            'global', 'global_org0', 'org0' => self::SCOPE_GLOBAL_ORG0,
            default => self::SCOPE_CURRENT_ORG,
        };
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return list<array<string,mixed>>
     */
    private function formatRows(Collection $rows): array
    {
        return $rows
            ->map(function (object $row): array {
                $successful = (int) ($row->successful_attempts ?? 0);
                $failed = (int) ($row->failed_attempts ?? 0);
                $total = (int) ($row->total_attempts ?? 0);

                return [
                    'day' => trim((string) ($row->day ?? '')),
                    'scale_code' => trim((string) ($row->scale_code ?? 'unknown')),
                    'scale_code_v2' => trim((string) ($row->scale_code_v2 ?? '')),
                    'form_code' => trim((string) ($row->form_code ?? '')),
                    'locale' => trim((string) ($row->locale ?? 'unknown')),
                    'started_attempts' => (int) ($row->started_attempts ?? 0),
                    'successful_attempts' => $successful,
                    'failed_attempts' => $failed,
                    'total_attempts' => $total,
                    'success_rate' => $this->safeRate($successful, $total),
                    'failure_rate' => $this->safeRate($failed, $total),
                    'last_refreshed_at' => trim((string) ($row->last_refreshed_at ?? '')),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return list<array<string,mixed>>
     */
    private function buildKpis(Collection $rows): array
    {
        $successful = (int) $rows->sum('successful_attempts');
        $failed = (int) $rows->sum('failed_attempts');
        $total = (int) $rows->sum('total_attempts');

        return [
            [
                'label' => __('ops.pages.test_kpi_daily.kpis.successful'),
                'value' => $successful,
                'description' => __('ops.pages.test_kpi_daily.kpis.success_rate', [
                    'rate' => $this->formatRate($this->safeRate($successful, $total)),
                ]),
            ],
            [
                'label' => __('ops.pages.test_kpi_daily.kpis.failed'),
                'value' => $failed,
                'description' => __('ops.pages.test_kpi_daily.kpis.failure_rate', [
                    'rate' => $this->formatRate($this->safeRate($failed, $total)),
                ]),
            ],
            [
                'label' => __('ops.pages.test_kpi_daily.kpis.total'),
                'value' => $total,
                'description' => __('ops.pages.test_kpi_daily.kpis.total_desc'),
            ],
            [
                'label' => __('ops.pages.test_kpi_daily.kpis.days'),
                'value' => $rows->pluck('day')->unique()->count(),
                'description' => __('ops.pages.test_kpi_daily.kpis.days_desc'),
            ],
        ];
    }

    private function safeRate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }
}
