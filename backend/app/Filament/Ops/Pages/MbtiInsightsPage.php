<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Analytics\MbtiInsightsSupport;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MbtiInsightsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Assessment Insights';

    protected static ?string $navigationLabel = 'MBTI Insights';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'mbti-insights';

    protected static string $view = 'filament.ops.pages.mbti-insights-page';

    public string $activeTab = 'overview';

    public string $fromDate = '';

    public string $toDate = '';

    public string $locale = 'all';

    public string $region = 'all';

    public string $contentPackageVersion = 'all';

    public string $scoringSpecVersion = 'all';

    public string $normVersion = 'all';

    /** @var array<string,string> */
    public array $localeOptions = [];

    /** @var array<string,string> */
    public array $regionOptions = [];

    /** @var array<string,string> */
    public array $contentPackageVersionOptions = [];

    /** @var array<string,string> */
    public array $scoringSpecVersionOptions = [];

    /** @var array<string,string> */
    public array $normVersionOptions = [];

    /** @var list<array<string,mixed>> */
    public array $kpis = [];

    /** @var list<array<string,mixed>> */
    public array $dailyTrend = [];

    /** @var list<array<string,mixed>> */
    public array $localeSplit = [];

    /** @var list<array<string,mixed>> */
    public array $versionSplit = [];

    /** @var list<array<string,mixed>> */
    public array $typeDistribution = [];

    /** @var list<array<string,mixed>> */
    public array $typeTrend = [];

    /** @var list<array<string,mixed>> */
    public array $axisSummary = [];

    /** @var list<array<string,mixed>> */
    public array $axisComparisonByLocale = [];

    /** @var list<array<string,mixed>> */
    public array $axisComparisonByVersion = [];

    /** @var list<string> */
    public array $scopeNotes = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array{scale_code:string,scale_code_v2:string,scale_uid:?string} */
    public array $canonicalScale = [
        'scale_code' => 'MBTI',
        'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
        'scale_uid' => null,
    ];

    public bool $hasData = false;

    public bool $showsAtAxis = false;

    public function mount(): void
    {
        $this->canonicalScale = app(MbtiInsightsSupport::class)->canonicalScale();
        $this->fromDate = now()->subDays(13)->toDateString();
        $this->toDate = now()->toDateString();
        $this->refreshPage();
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public function getTitle(): string
    {
        return 'MBTI Insights';
    }

    public function getSubheading(): ?string
    {
        return 'Results-rooted MBTI Overview, Type Distribution, and Axis Distribution with attempt-side locale, region, and version context.';
    }

    public function applyFilters(): void
    {
        $this->refreshPage();
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['overview', 'types', 'axes'], true)) {
            return;
        }

        $this->activeTab = $tab;
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

    public function typeDrillUrl(string $typeCode): string
    {
        return '/ops/results?tableSearch='.rawurlencode($typeCode);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('results')
                ->label('Results Explorer')
                ->url('/ops/results'),
        ];
    }

    private function refreshPage(): void
    {
        $this->warnings = [];
        $this->scopeNotes = [];
        $this->hasData = false;
        $this->showsAtAxis = false;
        $this->resetPanels();
        $this->loadFilterOptions();

        if (! SchemaBaseline::hasTable('analytics_mbti_type_daily') || ! SchemaBaseline::hasTable('analytics_axis_daily')) {
            $this->warnings[] = 'MBTI daily read models are missing. Run php artisan migrate first.';

            return;
        }

        [$from, $to] = $this->resolvedRange();
        if ($from->greaterThan($to)) {
            $this->warnings[] = 'The selected date range is invalid. Keep the start date on or before the end date.';

            return;
        }

        $orgId = $this->selectedOrgId();
        if ($orgId <= 0) {
            $this->warnings[] = 'Select an org before loading MBTI Insights.';

            return;
        }

        $typeRows = $this->scopedTypeQuery()
            ->orderBy('day')
            ->orderBy('type_code')
            ->get([
                'day',
                'locale',
                'region',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'type_code',
                'results_count',
                'distinct_attempts_with_results',
            ]);

        $axisRows = $this->scopedAxisQuery()
            ->orderBy('day')
            ->orderBy('axis_code')
            ->orderBy('side_code')
            ->get([
                'day',
                'locale',
                'region',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'axis_code',
                'side_code',
                'results_count',
                'distinct_attempts_with_results',
            ]);

        $this->hasData = $typeRows->isNotEmpty();
        if (! $this->hasData) {
            $this->warnings[] = 'No MBTI authority rows match the current scope. Refresh with php artisan analytics:refresh-mbti-daily --from='.$from->toDateString().' --to='.$to->toDateString().'.';

            return;
        }

        $totals = $this->summarizeTypeRows($typeRows);
        $atCoverage = (int) $axisRows->where('axis_code', 'AT')->sum('results_count');
        $this->showsAtAxis = $atCoverage > 0 && $atCoverage === (int) ($totals['total_results'] ?? 0);

        $this->kpis = $this->buildKpis($totals);
        $this->dailyTrend = $this->buildDailyTrend($typeRows);
        $this->localeSplit = $this->buildLocaleSplit($typeRows, (int) ($totals['total_results'] ?? 0));
        $this->versionSplit = $this->buildVersionSplit($typeRows, (int) ($totals['total_results'] ?? 0));
        $this->typeDistribution = $this->buildTypeDistribution($typeRows, (int) ($totals['total_results'] ?? 0));
        $this->typeTrend = $this->buildTypeTrend($typeRows);
        $this->axisSummary = $this->buildAxisSummary($axisRows, $this->showsAtAxis);
        $this->axisComparisonByLocale = $this->buildAxisComparisonByLocale($axisRows, $this->showsAtAxis);
        $this->axisComparisonByVersion = $this->buildAxisComparisonByVersion($axisRows, $this->showsAtAxis);
        $this->scopeNotes = $this->buildScopeNotes($this->showsAtAxis);
    }

    private function loadFilterOptions(): void
    {
        $this->localeOptions = [];
        $this->regionOptions = [];
        $this->contentPackageVersionOptions = [];
        $this->scoringSpecVersionOptions = [];
        $this->normVersionOptions = [];

        if (! SchemaBaseline::hasTable('analytics_mbti_type_daily')) {
            return;
        }

        $orgId = $this->selectedOrgId();
        if ($orgId <= 0) {
            return;
        }

        $base = DB::table('analytics_mbti_type_daily')->where('org_id', $orgId);
        $this->localeOptions = $this->distinctOptions(clone $base, 'locale');
        $this->regionOptions = $this->distinctOptions(clone $base, 'region');
        $this->contentPackageVersionOptions = $this->distinctOptions(clone $base, 'content_package_version');
        $this->scoringSpecVersionOptions = $this->distinctOptions(clone $base, 'scoring_spec_version');
        $this->normVersionOptions = $this->distinctOptions(clone $base, 'norm_version');
    }

    private function scopedTypeQuery(): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->resolvedRange();
        $query = DB::table('analytics_mbti_type_daily')
            ->where('org_id', $this->selectedOrgId())
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('scale_code', $this->canonicalScale['scale_code']);

        $this->applySharedFilters($query);

        return $query;
    }

    private function scopedAxisQuery(): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->resolvedRange();
        $query = DB::table('analytics_axis_daily')
            ->where('org_id', $this->selectedOrgId())
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('scale_code', $this->canonicalScale['scale_code']);

        $this->applySharedFilters($query);

        return $query;
    }

    private function applySharedFilters(\Illuminate\Database\Query\Builder $query): void
    {
        if ($this->locale !== 'all') {
            $query->where('locale', $this->locale);
        }
        if ($this->region !== 'all') {
            $query->where('region', $this->region);
        }
        if ($this->contentPackageVersion !== 'all') {
            $query->where('content_package_version', $this->contentPackageVersion);
        }
        if ($this->scoringSpecVersion !== 'all') {
            $query->where('scoring_spec_version', $this->scoringSpecVersion);
        }
        if ($this->normVersion !== 'all') {
            $query->where('norm_version', $this->normVersion);
        }
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function resolvedRange(): array
    {
        $from = CarbonImmutable::parse($this->fromDate !== '' ? $this->fromDate : now()->subDays(13)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($this->toDate !== '' ? $this->toDate : now()->toDateString())->startOfDay();

        return [$from, $to];
    }

    private function selectedOrgId(): int
    {
        $sessionOrgId = max(0, (int) session('ops_org_id', 0));
        if ($sessionOrgId > 0) {
            return $sessionOrgId;
        }

        return max(0, (int) app(OrgContext::class)->orgId());
    }

    /**
     * @return array<string,string>
     */
    private function distinctOptions(\Illuminate\Database\Query\Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->mapWithKeys(static fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @return array{
     *     total_results:int,
     *     distinct_attempts_with_results:int,
     *     top_type:string,
     *     top_type_share:?float,
     *     valid_locale_count:int
     * }
     */
    private function summarizeTypeRows(Collection $rows): array
    {
        $totalResults = (int) $rows->sum('results_count');
        $distinctAttempts = (int) $rows->sum('distinct_attempts_with_results');
        $byType = $rows
            ->groupBy('type_code')
            ->map(static fn (Collection $group): int => (int) $group->sum('results_count'))
            ->sortDesc();
        $topType = (string) ($byType->keys()->first() ?? 'n/a');
        $topCount = (int) ($byType->first() ?? 0);
        $validLocaleCount = (int) $rows
            ->pluck('locale')
            ->filter(static fn ($value): bool => trim((string) $value) !== '' && (string) $value !== 'unknown')
            ->unique()
            ->count();

        return [
            'total_results' => $totalResults,
            'distinct_attempts_with_results' => $distinctAttempts,
            'top_type' => $topType,
            'top_type_share' => $totalResults > 0 ? $topCount / $totalResults : null,
            'valid_locale_count' => $validLocaleCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $totals
     * @return list<array<string,mixed>>
     */
    private function buildKpis(array $totals): array
    {
        return [
            [
                'label' => 'Total results',
                'value' => (int) ($totals['total_results'] ?? 0),
                'description' => 'Authority scope only: MBTI-only results, linked attempts, valid result rows.',
            ],
            [
                'label' => 'Distinct attempts',
                'value' => (int) ($totals['distinct_attempts_with_results'] ?? 0),
                'description' => 'Distinct attempts with authoritative MBTI results in the current scope.',
            ],
            [
                'label' => 'Top type',
                'value' => 0,
                'display_value' => (string) ($totals['top_type'] ?? 'n/a'),
                'description' => '16-type ranking uses the MBTI base code and keeps A/T out of the main type split.',
            ],
            [
                'label' => 'Top type share',
                'value' => 0,
                'display_value' => $this->formatRate($totals['top_type_share'] ?? null),
                'description' => 'Share of the leading 16-type in the current scope.',
            ],
            [
                'label' => 'Valid locale count',
                'value' => (int) ($totals['valid_locale_count'] ?? 0),
                'description' => 'Locales with at least one authority result row in the current filter scope.',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildDailyTrend(Collection $rows): array
    {
        $daily = $rows
            ->groupBy('day')
            ->map(static fn (Collection $group): int => (int) $group->sum('results_count'));
        $max = max(1, (int) $daily->max());

        return $daily
            ->sortKeys()
            ->map(static fn (int $count, string $day) => [
                'day' => $day,
                'results_count' => $count,
                'pct' => round(($count / $max) * 100, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildLocaleSplit(Collection $rows, int $totalResults): array
    {
        return $rows
            ->groupBy('locale')
            ->map(function (Collection $group, string $locale) use ($totalResults): array {
                $typeMap = $group
                    ->groupBy('type_code')
                    ->map(static fn (Collection $rows): int => (int) $rows->sum('results_count'))
                    ->sortDesc();

                return [
                    'locale' => $locale,
                    'results_count' => (int) $group->sum('results_count'),
                    'share' => $totalResults > 0 ? ((int) $group->sum('results_count')) / $totalResults : null,
                    'top_type' => (string) ($typeMap->keys()->first() ?? 'n/a'),
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildVersionSplit(Collection $rows, int $totalResults): array
    {
        return $rows
            ->groupBy(static fn ($row): string => implode('|', [
                (string) $row->content_package_version,
                (string) $row->scoring_spec_version,
                (string) $row->norm_version,
            ]))
            ->map(function (Collection $group, string $key) use ($totalResults): array {
                [$contentVersion, $scoringVersion, $normVersion] = explode('|', $key);

                return [
                    'content_package_version' => $contentVersion,
                    'scoring_spec_version' => $scoringVersion,
                    'norm_version' => $normVersion,
                    'results_count' => (int) $group->sum('results_count'),
                    'share' => $totalResults > 0 ? ((int) $group->sum('results_count')) / $totalResults : null,
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildTypeDistribution(Collection $rows, int $totalResults): array
    {
        return $rows
            ->groupBy('type_code')
            ->map(function (Collection $group, string $typeCode) use ($totalResults): array {
                $count = (int) $group->sum('results_count');

                return [
                    'type_code' => $typeCode,
                    'results_count' => $count,
                    'share' => $totalResults > 0 ? $count / $totalResults : null,
                    'drill_url' => $this->typeDrillUrl($typeCode),
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildTypeTrend(Collection $rows): array
    {
        return $rows
            ->groupBy('day')
            ->map(function (Collection $group, string $day): array {
                $typeMap = $group
                    ->groupBy('type_code')
                    ->map(static fn (Collection $rows): int => (int) $rows->sum('results_count'))
                    ->sortDesc();
                $total = (int) $group->sum('results_count');
                $topCount = (int) ($typeMap->first() ?? 0);

                return [
                    'day' => $day,
                    'results_count' => $total,
                    'top_type' => (string) ($typeMap->keys()->first() ?? 'n/a'),
                    'top_type_share' => $total > 0 ? $topCount / $total : null,
                ];
            })
            ->sortBy('day')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildAxisSummary(Collection $rows, bool $includeAt): array
    {
        $allowedAxes = $includeAt ? ['EI', 'SN', 'TF', 'JP', 'AT'] : ['EI', 'SN', 'TF', 'JP'];
        $definitions = app(MbtiInsightsSupport::class)->axisDefinitions($includeAt);

        return $rows
            ->filter(static fn ($row): bool => in_array((string) $row->axis_code, $allowedAxes, true))
            ->groupBy('axis_code')
            ->map(function (Collection $group, string $axisCode) use ($definitions): array {
                $axisTotal = max(1, (int) $group->sum('results_count'));
                $sides = $group
                    ->groupBy('side_code')
                    ->map(function (Collection $rows, string $sideCode) use ($axisTotal): array {
                        $count = (int) $rows->sum('results_count');

                        return [
                            'side_code' => $sideCode,
                            'results_count' => $count,
                            'share' => $count / $axisTotal,
                        ];
                    })
                    ->sortByDesc('results_count')
                    ->values()
                    ->all();

                return [
                    'axis_code' => $axisCode,
                    'label' => $definitions[$axisCode]['label'] ?? $axisCode,
                    'results_count' => (int) $group->sum('results_count'),
                    'sides' => $sides,
                ];
            })
            ->sortBy(static fn (array $row): int => array_search($row['axis_code'], $allowedAxes, true))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildAxisComparisonByLocale(Collection $rows, bool $includeAt): array
    {
        $allowedAxes = $includeAt ? ['EI', 'SN', 'TF', 'JP', 'AT'] : ['EI', 'SN', 'TF', 'JP'];

        return $rows
            ->filter(static fn ($row): bool => in_array((string) $row->axis_code, $allowedAxes, true))
            ->groupBy('locale')
            ->map(function (Collection $group, string $locale) use ($allowedAxes): array {
                $leadMap = [];

                foreach ($allowedAxes as $axisCode) {
                    $axisGroup = $group->where('axis_code', $axisCode);
                    $axisTotal = (int) $axisGroup->sum('results_count');
                    if ($axisTotal <= 0) {
                        $leadMap[$axisCode] = 'n/a';
                        continue;
                    }

                    $leader = $axisGroup->groupBy('side_code')
                        ->map(static fn (Collection $rows): int => (int) $rows->sum('results_count'))
                        ->sortDesc();
                    $side = (string) ($leader->keys()->first() ?? 'n/a');
                    $count = (int) ($leader->first() ?? 0);
                    $leadMap[$axisCode] = $side.' '.$this->formatRate($count / $axisTotal);
                }

                return [
                    'locale' => $locale,
                    'results_count' => (int) $group->where('axis_code', 'EI')->sum('results_count'),
                    'lead_map' => $leadMap,
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildAxisComparisonByVersion(Collection $rows, bool $includeAt): array
    {
        $allowedAxes = $includeAt ? ['EI', 'SN', 'TF', 'JP', 'AT'] : ['EI', 'SN', 'TF', 'JP'];

        return $rows
            ->filter(static fn ($row): bool => in_array((string) $row->axis_code, $allowedAxes, true))
            ->groupBy(static fn ($row): string => implode('|', [
                (string) $row->content_package_version,
                (string) $row->scoring_spec_version,
            ]))
            ->map(function (Collection $group, string $key): array {
                [$contentVersion, $scoringVersion] = explode('|', $key);
                $axisSnapshot = $group
                    ->groupBy('axis_code')
                    ->map(function (Collection $rows): string {
                        $totals = $rows
                            ->groupBy('side_code')
                            ->map(static fn (Collection $rows): int => (int) $rows->sum('results_count'))
                            ->sortDesc();
                        $axisTotal = max(1, (int) $totals->sum());
                        $side = (string) ($totals->keys()->first() ?? 'n/a');
                        $count = (int) ($totals->first() ?? 0);

                        return $side.' '.$this->formatRate($count / $axisTotal);
                    })
                    ->sortKeys()
                    ->map(static fn (string $value, string $axisCode): string => $axisCode.': '.$value)
                    ->implode(' | ');

                return [
                    'content_package_version' => $contentVersion,
                    'scoring_spec_version' => $scoringVersion,
                    'results_count' => (int) $group->where('axis_code', 'EI')->sum('results_count'),
                    'axis_snapshot' => $axisSnapshot !== '' ? $axisSnapshot : 'n/a',
                ];
            })
            ->sortByDesc('results_count')
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function buildScopeNotes(bool $showsAtAxis): array
    {
        $notes = [
            'Authority scope is MBTI-only and results-based.',
            'The page excludes invalid rows, orphan results, and fallback-only result payloads without a direct results.type_code.',
            'Attempt-side locale, region, content_package_version, scoring_spec_version, and norm_version define the operational filter context.',
            'Paid, unlocked, share, channel, and commerce subsets are intentionally out of the first authority surface.',
            'Canonical scale scope is '.$this->canonicalScale['scale_code'].' with dual-write support for '.$this->canonicalScale['scale_code_v2'].'.',
            'Axis direction is derived from scores_pct first and falls back to type_code when scores_pct is missing. axis_states remain strength labels, not side winners.',
        ];

        if ($showsAtAxis) {
            $notes[] = 'A/T is included because the current filtered scope has full A/T coverage.';
        } else {
            $notes[] = 'A/T is omitted from the first authority axis panel when the current filtered scope lacks full A/T coverage.';
        }

        return $notes;
    }

    private function resetPanels(): void
    {
        $this->kpis = [];
        $this->dailyTrend = [];
        $this->localeSplit = [];
        $this->versionSplit = [];
        $this->typeDistribution = [];
        $this->typeTrend = [];
        $this->axisSummary = [];
        $this->axisComparisonByLocale = [];
        $this->axisComparisonByVersion = [];
    }
}
