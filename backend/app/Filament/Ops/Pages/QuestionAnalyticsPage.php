<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Analytics\QuestionAnalyticsSupport;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QuestionAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'question-analytics';

    protected static string $view = 'filament.ops.pages.question-analytics-page';

    public string $activeTab = 'option-distribution';

    public string $fromDate = '';

    public string $toDate = '';

    public string $scaleCode = 'BIG5_OCEAN';

    public string $locale = 'all';

    public string $region = 'all';

    public string $contentPackageVersion = 'all';

    public string $scoringSpecVersion = 'all';

    public string $normVersion = 'all';

    public string $questionId = 'all';

    public bool $onlyCompletedAttempts = false;

    public bool $excludeNonAuthoritativeScales = true;

    /** @var array<string,string> */
    public array $scaleOptions = [];

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

    /** @var array<string,string> */
    public array $questionIdOptions = [];

    /** @var list<array<string,mixed>> */
    public array $optionKpis = [];

    /** @var list<array<string,mixed>> */
    public array $progressKpis = [];

    /** @var list<array<string,mixed>> */
    public array $optionDistributionRows = [];

    /** @var list<array<string,mixed>> */
    public array $optionRankingRows = [];

    /** @var list<array<string,mixed>> */
    public array $optionLocaleCompareRows = [];

    /** @var list<array<string,mixed>> */
    public array $optionVersionCompareRows = [];

    /** @var list<array<string,mixed>> */
    public array $progressRows = [];

    /** @var list<array<string,mixed>> */
    public array $highestDropoffRows = [];

    /** @var list<array<string,mixed>> */
    public array $lowestCompletionRows = [];

    /** @var list<array<string,mixed>> */
    public array $progressLocaleCompareRows = [];

    /** @var list<array<string,mixed>> */
    public array $progressVersionCompareRows = [];

    /** @var list<string> */
    public array $scopeNotes = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array<string,string>> */
    public array $drillLinks = [];

    /** @var array{scale_code:string,scale_code_v2:string,scale_uid:?string} */
    public array $canonicalScale = [
        'scale_code' => 'BIG5_OCEAN',
        'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
        'scale_uid' => null,
    ];

    public bool $hasOptionData = false;

    public bool $hasProgressData = false;

    public function mount(): void
    {
        $this->canonicalScale = app(QuestionAnalyticsSupport::class)->canonicalScale('BIG5_OCEAN');
        $this->scaleCode = $this->canonicalScale['scale_code'];
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
        return __('ops.nav.question_analytics');
    }

    public function getSubheading(): ?string
    {
        return __('ops.pages.question_analytics.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.question_analytics');
    }

    public function applyFilters(): void
    {
        $this->refreshPage();
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['option-distribution', 'dropoff-completion'], true)) {
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

    public function questionLabel(string $questionId): string
    {
        $definition = app(QuestionAnalyticsSupport::class)->big5Definition();
        $question = $definition['questions'][$questionId] ?? null;
        if (! is_array($question)) {
            return $questionId;
        }

        $preferZh = str_starts_with(strtolower($this->locale), 'zh');
        $text = trim((string) ($preferZh ? ($question['text_zh'] ?? '') : ($question['text_en'] ?? '')));
        if ($text === '') {
            $text = trim((string) ($question['text_en'] ?? $question['text_zh'] ?? ''));
        }

        return $text !== '' ? $text : $questionId;
    }

    public function optionLabel(string $optionKey): string
    {
        $definition = app(QuestionAnalyticsSupport::class)->big5Definition();
        $labels = $definition['option_labels'][$optionKey] ?? null;
        if (! is_array($labels)) {
            return $optionKey;
        }

        $preferZh = str_starts_with(strtolower($this->locale), 'zh');
        $label = trim((string) ($preferZh ? ($labels['label_zh'] ?? '') : ($labels['label_en'] ?? '')));
        if ($label === '') {
            $label = trim((string) ($labels['label_en'] ?? $labels['label_zh'] ?? ''));
        }

        return $label !== '' ? $label : $optionKey;
    }

    public function barColor(string $key): string
    {
        return match (trim($key)) {
            '1' => '#dbeafe',
            '2' => '#93c5fd',
            '3' => '#60a5fa',
            '4' => '#2563eb',
            '5' => '#1d4ed8',
            default => '#94a3b8',
        };
    }

    private function refreshPage(): void
    {
        $this->enforceScope();
        $this->warnings = [];
        $this->scopeNotes = [];
        $this->drillLinks = [];
        $this->hasOptionData = false;
        $this->hasProgressData = false;
        $this->resetPanels();
        $this->loadFilterOptions();
        $this->drillLinks = $this->buildDrillLinks();

        if (! SchemaBaseline::hasTable('analytics_question_option_daily') || ! SchemaBaseline::hasTable('analytics_question_progress_daily')) {
            $this->warnings[] = __('ops.pages.question_analytics.missing_read_models');
            $this->scopeNotes = $this->buildScopeNotes();

            return;
        }

        [$from, $to] = $this->resolvedRange();
        if ($from->greaterThan($to)) {
            $this->warnings[] = __('ops.custom_pages.question_analytics.warnings.invalid_range');
            $this->scopeNotes = $this->buildScopeNotes();

            return;
        }

        $orgId = $this->selectedOrgId();
        if ($orgId <= 0) {
            $this->warnings[] = __('ops.pages.question_analytics.select_org_first');
            $this->scopeNotes = $this->buildScopeNotes();

            return;
        }

        $optionRows = $this->scopedOptionQuery()
            ->orderBy('question_order')
            ->orderBy('option_key')
            ->get([
                'day',
                'locale',
                'region',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'question_id',
                'question_order',
                'option_key',
                'answered_rows_count',
                'distinct_attempts_answered',
            ]);

        $progressRows = $this->scopedProgressQuery()
            ->orderBy('question_order')
            ->get([
                'day',
                'locale',
                'region',
                'content_package_version',
                'scoring_spec_version',
                'norm_version',
                'question_id',
                'question_order',
                'reached_attempts_count',
                'answered_attempts_count',
                'completed_attempts_count',
                'dropoff_attempts_count',
            ]);

        $this->hasOptionData = $optionRows->isNotEmpty();
        $this->hasProgressData = $progressRows->isNotEmpty();

        if (! $this->hasOptionData && ! $this->hasProgressData) {
            $this->warnings[] = __('ops.custom_pages.question_analytics.warnings.no_rows', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);
            $this->scopeNotes = $this->buildScopeNotes();

            return;
        }

        if ($this->hasOptionData) {
            $this->buildOptionPanels($optionRows);
        }

        if ($this->hasProgressData) {
            $this->buildProgressPanels($progressRows);
        }

        $this->scopeNotes = $this->buildScopeNotes();
    }

    private function loadFilterOptions(): void
    {
        $this->scaleOptions = [
            $this->canonicalScale['scale_code'] => $this->canonicalScale['scale_code'],
        ];
        $this->localeOptions = [];
        $this->regionOptions = [];
        $this->contentPackageVersionOptions = [];
        $this->scoringSpecVersionOptions = [];
        $this->normVersionOptions = [];
        $this->questionIdOptions = $this->buildQuestionIdOptions();

        if (! SchemaBaseline::hasTable('analytics_question_progress_daily')) {
            return;
        }

        $orgId = $this->selectedOrgId();
        if ($orgId <= 0) {
            return;
        }

        $base = DB::table('analytics_question_progress_daily')
            ->where('org_id', $orgId)
            ->where('scale_code', $this->canonicalScale['scale_code']);

        $this->localeOptions = $this->distinctOptions(clone $base, 'locale');
        $this->regionOptions = $this->distinctOptions(clone $base, 'region');
        $this->contentPackageVersionOptions = $this->distinctOptions(clone $base, 'content_package_version');
        $this->scoringSpecVersionOptions = $this->distinctOptions(clone $base, 'scoring_spec_version');
        $this->normVersionOptions = $this->distinctOptions(clone $base, 'norm_version');
    }

    private function scopedOptionQuery(): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->resolvedRange();

        $query = DB::table('analytics_question_option_daily')
            ->where('org_id', $this->selectedOrgId())
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('scale_code', $this->canonicalScale['scale_code']);

        $this->applySharedFilters($query);

        return $query;
    }

    private function scopedProgressQuery(): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->resolvedRange();

        $query = DB::table('analytics_question_progress_daily')
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
        if ($this->questionId !== 'all') {
            $query->where('question_id', $this->questionId);
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

    private function buildOptionPanels(Collection $rows): void
    {
        $questionRows = $this->optionQuestionSummaries($rows);
        $totalAnsweredRows = (int) $rows->sum('answered_rows_count');
        $attemptsWithAnswers = $this->scopeAttemptCountFromOptionQuestions($questionRows);
        $topAnsweredQuestion = $questionRows->sortByDesc('answer_count')->first();
        $totalQuestionsInScope = $this->questionId === 'all'
            ? (int) (app(QuestionAnalyticsSupport::class)->big5Definition()['total_questions'] ?? $questionRows->count())
            : 1;

        $this->optionKpis = [
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.total_answered_rows'),
                'value' => $totalAnsweredRows,
                'description' => __('ops.custom_pages.question_analytics.kpis.total_answered_rows_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.distinct_attempts_with_answers'),
                'value' => $attemptsWithAnswers,
                'description' => __('ops.custom_pages.question_analytics.kpis.distinct_attempts_with_answers_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.total_questions_in_scope'),
                'value' => $totalQuestionsInScope,
                'description' => __('ops.custom_pages.question_analytics.kpis.total_questions_in_scope_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.top_answered_question'),
                'value' => 0,
                'display_value' => is_array($topAnsweredQuestion)
                    ? '#'.$topAnsweredQuestion['question_order'].' / '.$topAnsweredQuestion['question_id']
                    : 'n/a',
                'description' => __('ops.custom_pages.question_analytics.kpis.top_answered_question_desc'),
            ],
        ];

        $this->optionDistributionRows = $questionRows
            ->sortBy('question_order')
            ->values()
            ->all();

        $this->optionRankingRows = $questionRows
            ->sortByDesc('answer_count')
            ->values()
            ->take(12)
            ->all();

        $this->optionLocaleCompareRows = $this->buildOptionLocaleCompareRows($rows);
        $this->optionVersionCompareRows = $this->buildOptionVersionCompareRows($rows);
    }

    private function buildProgressPanels(Collection $rows): void
    {
        $questionRows = $this->progressQuestionSummaries($rows);
        $selectedQuestion = $questionRows->sortBy('question_order')->first();
        $this->progressRows = $questionRows
            ->sortBy('question_order')
            ->values()
            ->all();

        $this->progressKpis = [
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.reached_attempts'),
                'value' => $this->questionId === 'all'
                    ? $this->firstQuestionMetric($questionRows, 'reached_attempts_count')
                    : (int) (($selectedQuestion['reached_attempts_count'] ?? 0)),
                'description' => __('ops.custom_pages.question_analytics.kpis.reached_attempts_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.answered_attempts'),
                'value' => $this->questionId === 'all'
                    ? $this->firstQuestionMetric($questionRows, 'answered_attempts_count')
                    : (int) (($selectedQuestion['answered_attempts_count'] ?? 0)),
                'description' => __('ops.custom_pages.question_analytics.kpis.answered_attempts_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.completed_attempts'),
                'value' => $this->questionId === 'all'
                    ? $this->firstQuestionMetric($questionRows, 'completed_attempts_count')
                    : (int) (($selectedQuestion['completed_attempts_count'] ?? 0)),
                'description' => __('ops.custom_pages.question_analytics.kpis.completed_attempts_desc'),
            ],
            [
                'label' => __('ops.custom_pages.question_analytics.kpis.dropoff_attempts'),
                'value' => $this->questionId === 'all'
                    ? (int) $questionRows->sum('dropoff_attempts_count')
                    : (int) (($selectedQuestion['dropoff_attempts_count'] ?? 0)),
                'description' => __('ops.custom_pages.question_analytics.kpis.dropoff_attempts_desc'),
            ],
        ];

        $this->highestDropoffRows = $questionRows
            ->filter(static fn (array $row): bool => (int) ($row['reached_attempts_count'] ?? 0) > 0)
            ->sortByDesc(static fn (array $row): array => [
                (float) ($row['dropoff_rate'] ?? 0),
                (int) ($row['dropoff_attempts_count'] ?? 0),
            ])
            ->values()
            ->take(12)
            ->all();

        $this->lowestCompletionRows = $questionRows
            ->filter(static fn (array $row): bool => (int) ($row['reached_attempts_count'] ?? 0) > 0)
            ->sortBy(static fn (array $row): array => [
                (float) ($row['completion_rate'] ?? 1),
                -1 * (int) ($row['dropoff_attempts_count'] ?? 0),
            ])
            ->values()
            ->take(12)
            ->all();

        $this->progressLocaleCompareRows = $this->buildProgressLocaleCompareRows($rows);
        $this->progressVersionCompareRows = $this->buildProgressVersionCompareRows($rows);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function optionQuestionSummaries(Collection $rows): Collection
    {
        return $rows
            ->groupBy(static fn ($row): string => (string) $row->question_id)
            ->map(function (Collection $group, string $questionId): array {
                $answerCount = (int) $group->sum('answered_rows_count');
                $optionSegments = $group
                    ->sortBy(static fn ($row): string => sprintf('%05s', (string) $row->option_key))
                    ->map(function ($row) use ($answerCount): array {
                        $count = (int) $row->answered_rows_count;
                        $share = $answerCount > 0 ? $count / $answerCount : null;

                        return [
                            'option_key' => (string) $row->option_key,
                            'label' => $this->optionLabel((string) $row->option_key),
                            'count' => $count,
                            'share' => $share,
                            'pct' => $share !== null ? round($share * 100, 1) : 0.0,
                        ];
                    })
                    ->values();

                $topOption = $optionSegments->sortByDesc('count')->first();

                return [
                    'question_id' => $questionId,
                    'question_order' => (int) ($group->min('question_order') ?? 0),
                    'question_label' => $this->questionLabel($questionId),
                    'answer_count' => $answerCount,
                    'distinct_attempts_answered' => (int) $group->sum('distinct_attempts_answered'),
                    'option_segments' => $optionSegments->all(),
                    'top_option_key' => (string) ($topOption['option_key'] ?? 'n/a'),
                    'top_option_share' => $topOption['share'] ?? null,
                ];
            });
    }

    private function scopeAttemptCountFromOptionQuestions(Collection $questionRows): int
    {
        if ($questionRows->isEmpty()) {
            return 0;
        }

        if ($this->questionId !== 'all') {
            return (int) (($questionRows->first()['distinct_attempts_answered'] ?? 0));
        }

        return (int) ($questionRows
            ->sortBy('question_order')
            ->first()['distinct_attempts_answered'] ?? 0);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function progressQuestionSummaries(Collection $rows): Collection
    {
        return $rows
            ->groupBy(static fn ($row): string => (string) $row->question_id)
            ->map(function (Collection $group, string $questionId): array {
                $reached = (int) $group->sum('reached_attempts_count');
                $answered = (int) $group->sum('answered_attempts_count');
                $completed = (int) $group->sum('completed_attempts_count');
                $dropoff = (int) $group->sum('dropoff_attempts_count');

                if ($this->onlyCompletedAttempts) {
                    $reached = $completed;
                    $answered = $completed;
                    $dropoff = 0;
                }

                return [
                    'question_id' => $questionId,
                    'question_order' => (int) ($group->min('question_order') ?? 0),
                    'question_label' => $this->questionLabel($questionId),
                    'reached_attempts_count' => $reached,
                    'answered_attempts_count' => $answered,
                    'completed_attempts_count' => $completed,
                    'dropoff_attempts_count' => $dropoff,
                    'dropoff_rate' => $reached > 0 ? $dropoff / $reached : null,
                    'completion_rate' => $reached > 0 ? $completed / $reached : null,
                ];
            });
    }

    private function firstQuestionMetric(Collection $rows, string $metric): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        return (int) ($rows->sortBy('question_order')->first()[$metric] ?? 0);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildOptionLocaleCompareRows(Collection $rows): array
    {
        return $rows
            ->groupBy('locale')
            ->map(function (Collection $group, string $locale): array {
                $questionRows = $this->optionQuestionSummaries($group);

                return [
                    'locale' => $locale,
                    'answered_rows_count' => (int) $group->sum('answered_rows_count'),
                    'distinct_attempts_answered' => $this->scopeAttemptCountFromOptionQuestions($questionRows),
                ];
            })
            ->sortByDesc('answered_rows_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildOptionVersionCompareRows(Collection $rows): array
    {
        return $rows
            ->groupBy(static fn ($row): string => implode('|', [
                (string) $row->content_package_version,
                (string) $row->scoring_spec_version,
                (string) $row->norm_version,
            ]))
            ->map(function (Collection $group, string $key): array {
                [$contentVersion, $scoringVersion, $normVersion] = explode('|', $key);
                $questionRows = $this->optionQuestionSummaries($group);

                return [
                    'content_package_version' => $contentVersion,
                    'scoring_spec_version' => $scoringVersion,
                    'norm_version' => $normVersion,
                    'answered_rows_count' => (int) $group->sum('answered_rows_count'),
                    'distinct_attempts_answered' => $this->scopeAttemptCountFromOptionQuestions($questionRows),
                ];
            })
            ->sortByDesc('answered_rows_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildProgressLocaleCompareRows(Collection $rows): array
    {
        return $rows
            ->groupBy('locale')
            ->map(function (Collection $group, string $locale): array {
                $questionRows = $this->progressQuestionSummaries($group);

                return [
                    'locale' => $locale,
                    'reached_attempts_count' => $this->firstQuestionMetric($questionRows, 'reached_attempts_count'),
                    'answered_attempts_count' => $this->firstQuestionMetric($questionRows, 'answered_attempts_count'),
                    'completed_attempts_count' => $this->firstQuestionMetric($questionRows, 'completed_attempts_count'),
                    'dropoff_attempts_count' => (int) $questionRows->sum('dropoff_attempts_count'),
                    'dropoff_rate' => $this->firstQuestionMetric($questionRows, 'reached_attempts_count') > 0
                        ? ((int) $questionRows->sum('dropoff_attempts_count')) / $this->firstQuestionMetric($questionRows, 'reached_attempts_count')
                        : null,
                    'completion_rate' => $this->firstQuestionMetric($questionRows, 'reached_attempts_count') > 0
                        ? $this->firstQuestionMetric($questionRows, 'completed_attempts_count') / $this->firstQuestionMetric($questionRows, 'reached_attempts_count')
                        : null,
                ];
            })
            ->sortByDesc('reached_attempts_count')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildProgressVersionCompareRows(Collection $rows): array
    {
        return $rows
            ->groupBy(static fn ($row): string => implode('|', [
                (string) $row->content_package_version,
                (string) $row->scoring_spec_version,
                (string) $row->norm_version,
            ]))
            ->map(function (Collection $group, string $key): array {
                [$contentVersion, $scoringVersion, $normVersion] = explode('|', $key);
                $questionRows = $this->progressQuestionSummaries($group);
                $reached = $this->firstQuestionMetric($questionRows, 'reached_attempts_count');
                $completed = $this->firstQuestionMetric($questionRows, 'completed_attempts_count');
                $dropoff = (int) $questionRows->sum('dropoff_attempts_count');

                return [
                    'content_package_version' => $contentVersion,
                    'scoring_spec_version' => $scoringVersion,
                    'norm_version' => $normVersion,
                    'reached_attempts_count' => $reached,
                    'answered_attempts_count' => $this->firstQuestionMetric($questionRows, 'answered_attempts_count'),
                    'completed_attempts_count' => $completed,
                    'dropoff_attempts_count' => $dropoff,
                    'dropoff_rate' => $reached > 0 ? $dropoff / $reached : null,
                    'completion_rate' => $reached > 0 ? $completed / $reached : null,
                ];
            })
            ->sortByDesc('reached_attempts_count')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function buildScopeNotes(): array
    {
        $notes = [
            __('ops.custom_pages.question_analytics.notes.fixed_scope'),
            __('ops.custom_pages.question_analytics.notes.question_order'),
            __('ops.custom_pages.question_analytics.notes.option_distribution'),
            __('ops.custom_pages.question_analytics.notes.dropoff_completion'),
            __('ops.custom_pages.question_analytics.notes.version_pools'),
        ];

        if ($this->onlyCompletedAttempts) {
            $notes[] = __('ops.custom_pages.question_analytics.notes.completed_only');
        }

        return $notes;
    }

    /**
     * @return array<string,string>
     */
    private function buildQuestionIdOptions(): array
    {
        $definition = app(QuestionAnalyticsSupport::class)->big5Definition();
        $questions = is_array($definition['questions'] ?? null) ? $definition['questions'] : [];

        $options = [];
        foreach ($questions as $questionId => $question) {
            if (! is_array($question)) {
                continue;
            }

            $order = (int) ($question['question_order'] ?? 0);
            $label = trim((string) (($question['text_en'] ?? '') ?: ($question['text_zh'] ?? '')));
            $options[(string) $questionId] = sprintf('#%d · %s · %s', $order, (string) $questionId, $label !== '' ? $label : (string) $questionId);
        }

        return $options;
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildDrillLinks(): array
    {
        $locale = $this->locale !== 'all' ? $this->locale : 'en';
        $frontendUrls = app(QuestionAnalyticsSupport::class)->frontendUrls($locale);

        $links = [];
        if ($frontendUrls['detail'] !== null) {
            $links[] = [
                'label' => __('ops.custom_pages.question_analytics.links.frontend_detail'),
                'url' => (string) $frontendUrls['detail'],
            ];
        }
        if ($frontendUrls['take'] !== null) {
            $links[] = [
                'label' => __('ops.custom_pages.question_analytics.links.frontend_take'),
                'url' => (string) $frontendUrls['take'],
            ];
        }

        $links[] = [
            'label' => __('ops.custom_pages.question_analytics.links.attempts_explorer'),
            'url' => app(QuestionAnalyticsSupport::class)->attemptExplorerUrl($this->questionId !== 'all' ? $this->questionId : null),
        ];

        return $links;
    }

    private function enforceScope(): void
    {
        $this->scaleCode = $this->canonicalScale['scale_code'];
        $this->excludeNonAuthoritativeScales = true;
        if ($this->activeTab === '') {
            $this->activeTab = 'option-distribution';
        }
    }

    private function resetPanels(): void
    {
        $this->optionKpis = [];
        $this->progressKpis = [];
        $this->optionDistributionRows = [];
        $this->optionRankingRows = [];
        $this->optionLocaleCompareRows = [];
        $this->optionVersionCompareRows = [];
        $this->progressRows = [];
        $this->highestDropoffRows = [];
        $this->lowestCompletionRows = [];
        $this->progressLocaleCompareRows = [];
        $this->progressVersionCompareRows = [];
    }
}
