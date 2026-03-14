<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FunnelConversionPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Funnel & Conversion';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'funnel-conversion';

    protected static string $view = 'filament.ops.pages.funnel-conversion-page';

    /** @var array<string,string> */
    private const DAILY_TREND_METRICS = [
        'started_attempts' => 'Started',
        'submitted_attempts' => 'Submitted',
        'order_created_attempts' => 'Order created',
        'paid_attempts' => 'Paid',
        'unlocked_attempts' => 'Unlocked',
        'report_ready_attempts' => 'Report ready',
    ];

    /** @var array<int,array{key:string,label:string,note:string}> */
    private const CONVERSION_STAGES = [
        [
            'key' => 'started_attempts',
            'label' => 'test_start',
            'note' => 'Hard fact: attempts.created_at',
        ],
        [
            'key' => 'submitted_attempts',
            'label' => 'test_submit_success',
            'note' => 'Hard fact first, then attempt_submissions / results fallback',
        ],
        [
            'key' => 'first_view_attempts',
            'label' => 'first_result_or_report_view',
            'note' => 'Behavioral mirror: normalized result_view / report_view events',
        ],
        [
            'key' => 'order_created_attempts',
            'label' => 'order_created',
            'note' => 'Hard fact: orders.created_at by target_attempt_id',
        ],
        [
            'key' => 'paid_attempts',
            'label' => 'payment_success',
            'note' => 'Hard fact first, payment_events fallback when paid_at is absent',
        ],
        [
            'key' => 'unlocked_attempts',
            'label' => 'unlock_success',
            'note' => 'Hard fact: active benefit_grants; order status alone is not enough',
        ],
        [
            'key' => 'report_ready_attempts',
            'label' => 'report_ready',
            'note' => 'Hard fact: ready/readable report_snapshots; report_jobs excluded',
        ],
    ];

    public string $fromDate = '';

    public string $toDate = '';

    public string $scaleCode = 'all';

    public string $locale = 'all';

    /** @var array<string,string> */
    public array $scaleOptions = [];

    /** @var array<string,string> */
    public array $localeOptions = [];

    /** @var list<array<string,mixed>> */
    public array $kpis = [];

    /** @var list<array<string,mixed>> */
    public array $dailyTrend = [];

    /** @var list<array<string,mixed>> */
    public array $conversionRows = [];

    /** @var list<array<string,mixed>> */
    public array $localeComparison = [];

    /** @var array<string,mixed> */
    public array $pdfPanel = [];

    /** @var array<string,mixed> */
    public array $sharePanel = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $hasData = false;

    public function mount(): void
    {
        $this->fromDate = now()->subDays(13)->toDateString();
        $this->toDate = now()->toDateString();
        $this->refreshPage();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_COMMERCE)
                || $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public function getTitle(): string
    {
        return 'Funnel & Conversion';
    }

    public function getSubheading(): ?string
    {
        return 'Attempt-led commerce funnel for the selected org. Business facts stay authoritative for order, payment, unlock, and report readiness; events fill only the behavioral gaps.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attempts')
                ->label('Attempts Explorer')
                ->url('/ops/attempts'),
            Action::make('orders')
                ->label('Orders')
                ->url('/ops/orders'),
            Action::make('paymentEvents')
                ->label('Payment Events')
                ->url('/ops/payment-events'),
            Action::make('orderLookup')
                ->label('Order Lookup')
                ->url('/ops/order-lookup'),
        ];
    }

    public function applyFilters(): void
    {
        $this->refreshPage();
    }

    public function refreshPage(): void
    {
        $this->warnings = [];
        $this->loadFilterOptions();

        if (! SchemaBaseline::hasTable('analytics_funnel_daily')) {
            $this->resetPanels();
            $this->warnings[] = 'analytics_funnel_daily is missing. Run php artisan migrate first.';

            return;
        }

        [$from, $to] = $this->resolvedRange();

        if ($from->greaterThan($to)) {
            $this->resetPanels();
            $this->warnings[] = 'The selected date range is invalid. Keep the start date on or before the end date.';

            return;
        }

        $rows = $this->scopedQuery()
            ->orderBy('day')
            ->get([
                'day',
                'scale_code',
                'locale',
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
            ]);

        $this->hasData = $rows->isNotEmpty();

        if (! $this->hasData) {
            $this->resetPanels();
            $this->warnings[] = 'No analytics_funnel_daily rows match the current scope. Refresh the read model with php artisan analytics:refresh-funnel-daily --from='.$from->toDateString().' --to='.$to->toDateString().'.';

            return;
        }

        $totals = $this->totalsFromRows($rows);
        $dailyRows = $this->dailyRows($rows, $from, $to);

        $this->kpis = $this->buildKpis($totals);
        $this->dailyTrend = $this->buildDailyTrend($dailyRows);
        $this->conversionRows = $this->buildConversionRows($totals);
        $this->localeComparison = $this->buildLocaleComparison();
        $this->pdfPanel = $this->buildPdfPanel($totals, $dailyRows);
        $this->sharePanel = $this->buildSharePanel($totals, $dailyRows);
    }

    public function formatInt(int $value): string
    {
        return number_format($value);
    }

    public function formatCurrencyCents(int $value): string
    {
        return '$'.number_format($value / 100, 2);
    }

    public function formatRate(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value * 100, 1).'%';
    }

    private function resetPanels(): void
    {
        $this->hasData = false;
        $this->kpis = [];
        $this->dailyTrend = [];
        $this->conversionRows = [];
        $this->localeComparison = [];
        $this->pdfPanel = [
            'downloads' => 0,
            'readiness_gap' => 0,
            'download_rate' => null,
            'trend' => [],
        ];
        $this->sharePanel = [
            'generated' => 0,
            'clicks' => 0,
            'click_rate' => null,
            'trend' => [],
        ];
    }

    private function loadFilterOptions(): void
    {
        $this->scaleOptions = [];
        $this->localeOptions = [];

        if (! SchemaBaseline::hasTable('analytics_funnel_daily')) {
            return;
        }

        $orgId = $this->currentOrgId();

        $this->scaleOptions = DB::table('analytics_funnel_daily')
            ->where('org_id', $orgId)
            ->whereNotNull('scale_code')
            ->where('scale_code', '!=', '')
            ->distinct()
            ->orderBy('scale_code')
            ->pluck('scale_code', 'scale_code')
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();

        $this->localeOptions = DB::table('analytics_funnel_daily')
            ->where('org_id', $orgId)
            ->whereNotNull('locale')
            ->where('locale', '!=', '')
            ->distinct()
            ->orderByRaw("case locale when 'en' then 0 when 'zh-CN' then 1 else 2 end")
            ->orderBy('locale')
            ->pluck('locale', 'locale')
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    private function currentOrgId(): int
    {
        return max(0, (int) app(OrgContext::class)->orgId());
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

    private function scopedQuery(bool $applyLocaleFilter = true)
    {
        [$from, $to] = $this->resolvedRange();

        $query = DB::table('analytics_funnel_daily')
            ->where('org_id', $this->currentOrgId())
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        if ($this->scaleCode !== 'all') {
            $query->where('scale_code', $this->scaleCode);
        }

        if ($applyLocaleFilter && $this->locale !== 'all') {
            $query->where('locale', $this->locale);
        }

        return $query;
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function totalsFromRows(Collection $rows): array
    {
        $metrics = [
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
        ];

        $totals = array_fill_keys($metrics, 0);

        foreach ($rows as $row) {
            foreach ($metrics as $metric) {
                $totals[$metric] += (int) ($row->{$metric} ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return list<array<string,int|string>>
     */
    private function dailyRows(Collection $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $cursor = $from->startOfDay();

        while ($cursor->lessThanOrEqualTo($to)) {
            $day = $cursor->toDateString();
            $days[$day] = [
                'day' => $day,
                'started_attempts' => 0,
                'submitted_attempts' => 0,
                'order_created_attempts' => 0,
                'paid_attempts' => 0,
                'unlocked_attempts' => 0,
                'report_ready_attempts' => 0,
                'pdf_download_attempts' => 0,
                'share_generated_attempts' => 0,
                'share_click_attempts' => 0,
            ];
            $cursor = $cursor->addDay();
        }

        foreach ($rows as $row) {
            $day = trim((string) ($row->day ?? ''));
            if ($day === '' || ! isset($days[$day])) {
                continue;
            }

            foreach (array_keys($days[$day]) as $metric) {
                if ($metric === 'day') {
                    continue;
                }

                $days[$day][$metric] += (int) ($row->{$metric} ?? 0);
            }
        }

        return array_values($days);
    }

    /**
     * @param  array<string,int>  $totals
     * @return list<array<string,mixed>>
     */
    private function buildKpis(array $totals): array
    {
        return [
            [
                'label' => 'Started attempts',
                'value' => (int) ($totals['started_attempts'] ?? 0),
                'description' => 'Authority: attempts.created_at',
            ],
            [
                'label' => 'Submitted attempts',
                'value' => (int) ($totals['submitted_attempts'] ?? 0),
                'description' => 'submit/start '.$this->formatRate($this->safeRate(
                    (int) ($totals['submitted_attempts'] ?? 0),
                    (int) ($totals['started_attempts'] ?? 0)
                )),
            ],
            [
                'label' => 'First result/report viewers',
                'value' => (int) ($totals['first_view_attempts'] ?? 0),
                'description' => 'view/submit '.$this->formatRate($this->safeRate(
                    (int) ($totals['first_view_attempts'] ?? 0),
                    (int) ($totals['submitted_attempts'] ?? 0)
                )),
            ],
            [
                'label' => 'Order-created attempts',
                'value' => (int) ($totals['order_created_attempts'] ?? 0),
                'description' => 'Hard fact: orders.created_at',
            ],
            [
                'label' => 'Paid attempts',
                'value' => (int) ($totals['paid_attempts'] ?? 0),
                'description' => 'paid/order '.$this->formatRate($this->safeRate(
                    (int) ($totals['paid_attempts'] ?? 0),
                    (int) ($totals['order_created_attempts'] ?? 0)
                )),
            ],
            [
                'label' => 'Revenue',
                'value' => (int) ($totals['paid_revenue_cents'] ?? 0),
                'description' => 'Attempt-led dims, order-level paid revenue',
                'currency' => true,
            ],
            [
                'label' => 'Unlocked attempts',
                'value' => (int) ($totals['unlocked_attempts'] ?? 0),
                'description' => 'unlock/paid '.$this->formatRate($this->safeRate(
                    (int) ($totals['unlocked_attempts'] ?? 0),
                    (int) ($totals['paid_attempts'] ?? 0)
                )),
            ],
            [
                'label' => 'Report-ready attempts',
                'value' => (int) ($totals['report_ready_attempts'] ?? 0),
                'description' => 'ready/unlock '.$this->formatRate($this->safeRate(
                    (int) ($totals['report_ready_attempts'] ?? 0),
                    (int) ($totals['unlocked_attempts'] ?? 0)
                )),
            ],
        ];
    }

    /**
     * @param  list<array<string,int|string>>  $dailyRows
     * @return list<array<string,mixed>>
     */
    private function buildDailyTrend(array $dailyRows): array
    {
        $maxima = [];

        foreach (array_keys(self::DAILY_TREND_METRICS) as $metric) {
            $maxima[$metric] = max(array_map(static fn (array $row): int => (int) ($row[$metric] ?? 0), $dailyRows));
        }

        return array_map(function (array $row) use ($maxima): array {
            $entry = ['day' => $row['day']];

            foreach (self::DAILY_TREND_METRICS as $metric => $label) {
                $value = (int) ($row[$metric] ?? 0);
                $entry[$metric] = $value;
                $entry[$metric.'_pct'] = $this->maxPercentage($value, (int) ($maxima[$metric] ?? 0));
                $entry[$metric.'_label'] = $label;
            }

            return $entry;
        }, $dailyRows);
    }

    /**
     * @param  array<string,int>  $totals
     * @return list<array<string,mixed>>
     */
    private function buildConversionRows(array $totals): array
    {
        $rows = [];
        $startValue = (int) ($totals['started_attempts'] ?? 0);
        $previousValue = null;

        foreach (self::CONVERSION_STAGES as $stage) {
            $value = (int) ($totals[$stage['key']] ?? 0);
            $rows[] = [
                'label' => $stage['label'],
                'value' => $value,
                'previous_step_rate' => $previousValue === null ? null : $this->safeRate($value, $previousValue),
                'cumulative_rate' => $this->safeRate($value, $startValue),
                'note' => $stage['note'],
            ];

            $previousValue = $value;
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildLocaleComparison(): array
    {
        $rows = $this->scopedQuery($this->locale !== 'all')
            ->select('locale')
            ->selectRaw('SUM(started_attempts) as started_attempts')
            ->selectRaw('SUM(submitted_attempts) as submitted_attempts')
            ->selectRaw('SUM(paid_attempts) as paid_attempts')
            ->selectRaw('SUM(unlocked_attempts) as unlocked_attempts')
            ->selectRaw('SUM(report_ready_attempts) as report_ready_attempts')
            ->groupBy('locale')
            ->orderByRaw("case locale when 'en' then 0 when 'zh-CN' then 1 else 2 end")
            ->orderBy('locale')
            ->get();

        return $rows
            ->map(function (object $row): array {
                $started = (int) ($row->started_attempts ?? 0);
                $submitted = (int) ($row->submitted_attempts ?? 0);
                $paid = (int) ($row->paid_attempts ?? 0);
                $unlocked = (int) ($row->unlocked_attempts ?? 0);
                $ready = (int) ($row->report_ready_attempts ?? 0);

                return [
                    'locale' => trim((string) ($row->locale ?? 'unknown')),
                    'started_attempts' => $started,
                    'submitted_attempts' => $submitted,
                    'paid_attempts' => $paid,
                    'unlocked_attempts' => $unlocked,
                    'report_ready_attempts' => $ready,
                    'submit_rate' => $this->safeRate($submitted, $started),
                    'paid_rate' => $this->safeRate($paid, $submitted),
                    'ready_rate' => $this->safeRate($ready, $unlocked),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string,int>  $totals
     * @param  list<array<string,int|string>>  $dailyRows
     * @return array<string,mixed>
     */
    private function buildPdfPanel(array $totals, array $dailyRows): array
    {
        return [
            'downloads' => (int) ($totals['pdf_download_attempts'] ?? 0),
            'readiness_gap' => max(
                0,
                (int) ($totals['report_ready_attempts'] ?? 0) - (int) ($totals['pdf_download_attempts'] ?? 0)
            ),
            'download_rate' => $this->safeRate(
                (int) ($totals['pdf_download_attempts'] ?? 0),
                (int) ($totals['report_ready_attempts'] ?? 0)
            ),
            'trend' => array_slice(array_map(static function (array $row): array {
                return [
                    'day' => $row['day'],
                    'report_ready_attempts' => (int) ($row['report_ready_attempts'] ?? 0),
                    'pdf_download_attempts' => (int) ($row['pdf_download_attempts'] ?? 0),
                ];
            }, $dailyRows), -7),
        ];
    }

    /**
     * @param  array<string,int>  $totals
     * @param  list<array<string,int|string>>  $dailyRows
     * @return array<string,mixed>
     */
    private function buildSharePanel(array $totals, array $dailyRows): array
    {
        return [
            'generated' => (int) ($totals['share_generated_attempts'] ?? 0),
            'clicks' => (int) ($totals['share_click_attempts'] ?? 0),
            'click_rate' => $this->safeRate(
                (int) ($totals['share_click_attempts'] ?? 0),
                (int) ($totals['share_generated_attempts'] ?? 0)
            ),
            'trend' => array_slice(array_map(static function (array $row): array {
                return [
                    'day' => $row['day'],
                    'share_generated_attempts' => (int) ($row['share_generated_attempts'] ?? 0),
                    'share_click_attempts' => (int) ($row['share_click_attempts'] ?? 0),
                ];
            }, $dailyRows), -7),
        ];
    }

    private function safeRate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    private function maxPercentage(int $value, int $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }

        return round(($value / $max) * 100, 2);
    }
}
