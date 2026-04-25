<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Contracts\Cms\SiblingTranslationAdapter;
use App\Filament\Ops\Support\StatusBadge;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

final class CmsTranslationOpsService
{
    /**
     * @var array<string, SiblingTranslationAdapter>
     */
    private array $adapters;

    public function __construct(
        private readonly ArticleTranslationOpsService $articleOps,
        private readonly ArticleTranslationWorkflowService $articleWorkflow,
        private readonly SiblingTranslationWorkflowService $siblingWorkflow,
    ) {
        $this->adapters = [
            'support_article' => $this->siblingWorkflow->adapter('support_article'),
            'interpretation_guide' => $this->siblingWorkflow->adapter('interpretation_guide'),
            'content_page' => $this->siblingWorkflow->adapter('content_page'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     metrics: array<string, int>,
     *     summary_cards: list<array<string, mixed>>,
     *     locale_columns: list<string>,
     *     coverage_matrix: list<array<string, mixed>>,
     *     groups: list<array<string, mixed>>,
     *     selected_group: array<string, mixed>|null,
     *     filter_options: array<string, list<string>>
     * }
     */
    public function dashboard(array $filters = [], ?string $selectedGroupKey = null): array
    {
        $groups = collect()
            ->merge($this->articleGroups($filters))
            ->merge($this->siblingGroups('support_article'))
            ->merge($this->siblingGroups('interpretation_guide'))
            ->merge($this->siblingGroups('content_page'));

        $filtered = $this->applyFilters($groups, $filters)->values();

        return [
            'metrics' => $this->metrics($groups),
            'summary_cards' => $this->summaryCards($groups),
            'locale_columns' => $this->localeColumns($filtered),
            'coverage_matrix' => $this->coverageMatrix($filtered),
            'groups' => $filtered->all(),
            'selected_group' => $filtered->firstWhere('group_key', $selectedGroupKey)
                ?? $groups->firstWhere('group_key', $selectedGroupKey),
            'filter_options' => $this->filterOptions($groups),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function articleGroups(array $filters): Collection
    {
        $articleFilters = [
            'slug' => $filters['slug'] ?? '',
            'source_locale' => $filters['source_locale'] ?? 'all',
            'target_locale' => $filters['target_locale'] ?? 'all',
            'translation_status' => $filters['translation_status'] ?? 'all',
            'stale' => $filters['stale'] ?? 'all',
            'published' => $filters['published'] ?? 'all',
            'missing_locale' => (bool) ($filters['missing_locale'] ?? false),
            'ownership' => $filters['ownership'] ?? 'all',
        ];

        return collect($this->articleOps->dashboard($articleFilters)['groups'] ?? [])
            ->map(fn (array $group): array => [
                'group_key' => 'article:'.$group['translation_group_id'],
                'content_type' => 'article',
                'content_type_label' => $this->contentTypeLabel('article'),
                'translation_group_id' => (string) $group['translation_group_id'],
                'slug' => (string) $group['slug'],
                'source_locale' => (string) $group['source_locale'],
                'source_record_id' => $group['source_article_id'],
                'source_status' => (string) $group['source_article_status'],
                'source_edit_url' => $group['source_edit_url'],
                'latest_source_hash' => $group['latest_source_hash'],
                'locales' => array_map(fn (array $locale): array => [
                    'record_id' => (int) $locale['article_id'],
                    'content_type' => 'article',
                    'locale' => (string) $locale['locale'],
                    'source_locale' => (string) $locale['source_locale'],
                    'is_source' => (bool) $locale['is_source'],
                    'translation_status' => (string) $locale['translation_status'],
                    'record_status' => (string) $locale['article_status'],
                    'is_public' => (bool) $locale['is_public'],
                    'is_published' => (bool) $locale['is_published'],
                    'is_stale' => (bool) $locale['is_stale'],
                    'published_at' => $locale['published_at'],
                    'source_record_id' => $locale['source_article_id'],
                    'working_revision_id' => $locale['working_revision_id'],
                    'published_revision_id' => $locale['published_revision_id'],
                    'source_version_hash' => $locale['source_version_hash'],
                    'translated_from_version_hash' => $locale['translated_from_version_hash'],
                    'ownership_ok' => (bool) $locale['ownership_ok'],
                    'ownership_issues' => $locale['ownership_issues'],
                    'edit_url' => $locale['edit_url'],
                    'preflight' => $this->localizedPreflight((array) $locale['preflight']),
                    'readiness_blockers' => (array) data_get($locale, 'preflight.blockers', []),
                    'compare_summary' => $locale['compare_summary'],
                    'workflow_kind' => 'revision',
                    'actions' => $this->articleLocaleActions($locale),
                ], $group['locales'] ?? []),
                'published_locales' => $group['published_locales'] ?? [],
                'published_target_locales' => $this->publishedTargetLocales(array_merge(
                    (array) ($group['coverage'] ?? []),
                    [
                        'published_target_locales' => array_values(array_intersect(
                            $this->targetLocales(),
                            array_map(
                                static fn (mixed $locale): string => (string) $locale,
                                (array) ($group['published_locales'] ?? [])
                            )
                        )),
                    ],
                )),
                'coverage' => $group['coverage'] ?? [],
                'stale_locales_count' => (int) ($group['stale_locales_count'] ?? 0),
                'ownership_ok' => (bool) ($group['ownership_ok'] ?? false),
                'ownership_issues' => $group['ownership_issues'] ?? [],
                'canonical_ok' => (bool) ($group['canonical_ok'] ?? false),
                'canonical_issues' => $group['canonical_issues'] ?? [],
                'alerts' => $group['alerts'] ?? [],
                'group_actions' => $this->articleGroupActions($group),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function siblingGroups(string $contentType): Collection
    {
        $adapter = $this->adapters[$contentType];
        $modelClass = $adapter->modelClass();

        /** @var Collection<int, Model> $records */
        $records = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereNotNull('translation_group_id')
            ->orderBy('translation_group_id')
            ->orderBy('locale')
            ->get();

        return $records
            ->groupBy(fn (Model $record): string => (string) $record->translation_group_id)
            ->map(fn (Collection $groupRecords, string $groupId): array => $this->summarizeSiblingGroup($contentType, $adapter, $groupId, $groupRecords))
            ->values();
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return array<string, mixed>
     */
    private function summarizeSiblingGroup(string $contentType, SiblingTranslationAdapter $adapter, string $groupId, Collection $records): array
    {
        $source = $records->first(fn (Model $record): bool => $adapter->isSource($record)) ?? $records->first();
        $locales = $records
            ->sortBy(fn (Model $record): string => ($adapter->isSource($record) ? '0-' : '1-').(string) $record->locale)
            ->map(fn (Model $record): array => $this->summarizeSiblingLocale($contentType, $adapter, $record, $source))
            ->values();

        $coverage = $this->coverage($locales, $this->targetLocales());
        $alerts = [];
        if (($coverage['missing_target_locales'] ?? []) !== []) {
            foreach ($coverage['missing_target_locales'] as $locale) {
                $alerts[] = ['label' => __('ops.translation_ops.alerts.missing_locale', ['locale' => $locale])];
            }
        }
        if ($locales->contains(fn (array $locale): bool => (bool) $locale['is_stale'])) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.source_updated_after_target_review')];
        }
        if ($locales->contains(fn (array $locale): bool => ! (bool) $locale['ownership_ok'])) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.ownership_mismatch')];
        }

        return [
            'group_key' => $contentType.':'.$groupId,
            'content_type' => $contentType,
            'content_type_label' => $this->contentTypeLabel($contentType),
            'translation_group_id' => $groupId,
            'slug' => (string) ($source?->slug ?? ''),
            'source_locale' => (string) ($source?->locale ?? ''),
            'source_record_id' => $source?->id ? (int) $source->id : null,
            'source_status' => (string) ($source?->status ?? 'missing'),
            'source_edit_url' => $source instanceof Model ? $adapter->editUrl($source) : null,
            'latest_source_hash' => $source?->source_version_hash,
            'locales' => $locales->all(),
            'published_locales' => $locales->where('is_published', true)->pluck('locale')->all(),
            'published_target_locales' => $this->publishedTargetLocales($coverage),
            'coverage' => $coverage,
            'stale_locales_count' => $locales->where('is_stale', true)->count(),
            'ownership_ok' => ! $locales->contains(fn (array $locale): bool => ! (bool) $locale['ownership_ok']),
            'ownership_issues' => $locales->flatMap(fn (array $locale): array => $locale['ownership_issues'])->unique()->values()->all(),
            'canonical_ok' => ! $records->where('translation_status', 'source')->skip(1)->isNotEmpty(),
            'canonical_issues' => $records->where('translation_status', 'source')->count() > 1 ? ['multiple source rows'] : [],
            'alerts' => $alerts,
            'group_actions' => $this->siblingGroupActions($contentType, $adapter, $source, $coverage),
        ];
    }

    private function summarizeSiblingLocale(
        string $contentType,
        SiblingTranslationAdapter $adapter,
        Model $record,
        ?Model $source
    ): array {
        $isSource = $adapter->isSource($record);
        $isStale = ! $isSource && $this->siblingWorkflow->isStale($adapter, $record);
        $preflight = $isSource ? ['ok' => true, 'blockers' => []] : $this->siblingWorkflow->preflight($contentType, $record);
        $preflight = $this->localizedPreflight($preflight);
        $ownershipIssues = [];

        if ((int) $record->org_id !== 0) {
            $ownershipIssues[] = __('ops.translation_ops.ownership_issues.row_org_mismatch');
        }
        if (! $isSource && ! filled($record->source_content_id)) {
            $ownershipIssues[] = __('ops.translation_ops.ownership_issues.source_linkage_missing');
        }

        return [
            'record_id' => (int) $record->id,
            'content_type' => $contentType,
            'locale' => (string) $record->locale,
            'source_locale' => (string) $record->source_locale,
            'is_source' => $isSource,
            'translation_status' => (string) $record->translation_status,
            'record_status' => (string) $record->status,
            'is_public' => (bool) data_get($record, 'is_public', false),
            'is_published' => $adapter->isPublished($record),
            'is_stale' => $isStale || (string) $record->translation_status === 'stale',
            'published_at' => optional($record->published_at)?->toISOString(),
            'source_record_id' => $record->source_content_id ? (int) $record->source_content_id : null,
            'working_revision_id' => $record->working_revision_id ? (int) $record->working_revision_id : null,
            'published_revision_id' => $record->published_revision_id ? (int) $record->published_revision_id : null,
            'source_version_hash' => $record->source_version_hash,
            'translated_from_version_hash' => $record->translated_from_version_hash,
            'ownership_ok' => $ownershipIssues === [],
            'ownership_issues' => $ownershipIssues,
            'edit_url' => $adapter->editUrl($record),
            'preflight' => $preflight,
            'compare_summary' => [
                __('ops.translation_ops.compare.shadow_revision_workflow'),
                $record->working_revision_id
                    ? __('ops.translation_ops.compare.working_revision_present')
                    : __('ops.translation_ops.compare.working_revision_missing'),
                $isStale
                    ? __('ops.translation_ops.compare.source_hash_drift_detected')
                    : __('ops.translation_ops.compare.source_hash_aligned'),
            ],
            'workflow_kind' => 'shadow_revision',
            'workflow_kind_label' => __('ops.translation_ops.compare.shadow_revision_workflow'),
            'actions' => $this->siblingLocaleActions($contentType, $adapter, $record, $isSource, $isStale),
            'readiness_blockers' => (array) ($preflight['blockers'] ?? []),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $groups, array $filters): Collection
    {
        return $groups
            ->filter(function (array $group) use ($filters): bool {
                $contentType = (string) ($filters['content_type'] ?? 'all');
                if ($contentType !== 'all' && $group['content_type'] !== $contentType) {
                    return false;
                }

                $slug = trim((string) ($filters['slug'] ?? ''));
                if ($slug !== '' && ! str_contains((string) $group['slug'], $slug)) {
                    return false;
                }

                $sourceLocale = (string) ($filters['source_locale'] ?? 'all');
                if ($sourceLocale !== 'all' && (string) $group['source_locale'] !== $sourceLocale) {
                    return false;
                }

                $targetLocale = (string) ($filters['target_locale'] ?? 'all');
                if ($targetLocale !== 'all') {
                    $hasTargetLocale = collect($group['locales'] ?? [])->contains(
                        fn (array $locale): bool => ! (bool) $locale['is_source'] && (string) $locale['locale'] === $targetLocale
                    );
                    if (! $hasTargetLocale && ! (bool) ($filters['missing_locale'] ?? false)) {
                        return false;
                    }
                    if ((bool) ($filters['missing_locale'] ?? false)
                        && ! in_array($targetLocale, $group['coverage']['missing_target_locales'] ?? [], true)) {
                        return false;
                    }
                }

                $translationStatus = (string) ($filters['translation_status'] ?? 'all');
                if ($translationStatus !== 'all' && ! collect($group['locales'] ?? [])->contains(
                    fn (array $locale): bool => (string) $locale['translation_status'] === $translationStatus
                )) {
                    return false;
                }

                $stale = (string) ($filters['stale'] ?? 'all');
                if ($stale === 'stale' && (int) $group['stale_locales_count'] < 1) {
                    return false;
                }
                if ($stale === 'current' && (int) $group['stale_locales_count'] > 0) {
                    return false;
                }

                $published = (string) ($filters['published'] ?? 'all');
                if ($published === 'published' && ($group['published_locales'] ?? []) === []) {
                    return false;
                }
                if ($published === 'unpublished' && ($group['published_locales'] ?? []) !== []) {
                    return false;
                }

                $ownership = (string) ($filters['ownership'] ?? 'all');
                if ($ownership === 'mismatch' && (bool) $group['ownership_ok']) {
                    return false;
                }
                if ($ownership === 'ok' && ! (bool) $group['ownership_ok']) {
                    return false;
                }

                if ((bool) ($filters['missing_locale'] ?? false) && ($group['coverage']['missing_target_locales'] ?? []) === []) {
                    return false;
                }

                return true;
            })
            ->sortBy(fn (array $group): string => $group['content_type_label'].'-'.$group['slug']);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, int>
     */
    private function metrics(Collection $groups): array
    {
        $publishedTargetLocaleCount = $groups->sum(fn (array $group): int => count($group['published_target_locales'] ?? []));
        $targetSlotCount = $groups->sum(fn (array $group): int => count($group['coverage']['target_locales'] ?? []));
        $blockedActionCount = $groups->sum(fn (array $group): int => $this->blockedActionCount($group));
        $stalePublishedCount = $groups->sum(fn (array $group): int => collect($group['locales'] ?? [])
            ->filter(fn (array $locale): bool => (bool) ($locale['is_stale'] ?? false) && (bool) ($locale['is_published'] ?? false))
            ->count());
        $staleDraftCount = $groups->sum(fn (array $group): int => collect($group['locales'] ?? [])
            ->filter(fn (array $locale): bool => (bool) ($locale['is_stale'] ?? false) && ! (bool) ($locale['is_published'] ?? false))
            ->count());

        return [
            'translation_groups' => $groups->count(),
            'stale_groups' => $groups->where('stale_locales_count', '>', 0)->count(),
            'published_groups' => $groups->filter(fn (array $group): bool => ($group['published_target_locales'] ?? []) !== [])->count(),
            'published_target_locale_count' => $publishedTargetLocaleCount,
            'target_slot_count' => $targetSlotCount,
            'published_target_coverage_rate' => $targetSlotCount > 0 ? (int) round(($publishedTargetLocaleCount / $targetSlotCount) * 100) : 0,
            'missing_target_locale' => $groups->filter(fn (array $group): bool => ($group['coverage']['missing_target_locales'] ?? []) !== [])->count(),
            'missing_translation_count' => $groups->sum(fn (array $group): int => count($group['coverage']['missing_target_locales'] ?? [])),
            'stale_translation_count' => $groups->sum(fn (array $group): int => (int) ($group['stale_locales_count'] ?? 0)),
            'stale_published_count' => $stalePublishedCount,
            'stale_draft_count' => $staleDraftCount,
            'blocked_action_count' => $blockedActionCount,
            'ownership_mismatch_groups' => $groups->where('ownership_ok', false)->count(),
            'canonical_risk_groups' => $groups->where('canonical_ok', false)->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function summaryCards(Collection $groups): array
    {
        $metrics = $this->metrics($groups);

        return [
            [
                'label' => __('ops.translation_ops.summary.published_target_coverage'),
                'value' => __('ops.translation_ops.summary.coverage_rate', ['rate' => $metrics['published_target_coverage_rate']]),
                'hint' => __('ops.translation_ops.summary.published_target_coverage_hint', [
                    'published' => $metrics['published_target_locale_count'],
                    'slots' => $metrics['target_slot_count'],
                    'groups' => $metrics['published_groups'],
                ]),
                'state' => $metrics['published_target_coverage_rate'] >= 90 ? 'success' : 'warning',
            ],
            [
                'label' => __('ops.translation_ops.summary.missing_translations'),
                'value' => (string) $metrics['missing_translation_count'],
                'hint' => __('ops.translation_ops.summary.missing_translations_hint', [
                    'groups' => $metrics['missing_target_locale'],
                ]),
                'state' => $metrics['missing_translation_count'] > 0 ? 'warning' : 'success',
            ],
            [
                'label' => __('ops.translation_ops.summary.stale_translations'),
                'value' => (string) $metrics['stale_translation_count'],
                'hint' => __('ops.translation_ops.summary.stale_translations_hint', [
                    'published' => $metrics['stale_published_count'],
                    'drafts' => $metrics['stale_draft_count'],
                ]),
                'state' => $metrics['stale_translation_count'] > 0 ? 'failed' : 'success',
            ],
            [
                'label' => __('ops.translation_ops.summary.blocked_actions'),
                'value' => (string) $metrics['blocked_action_count'],
                'hint' => __('ops.translation_ops.summary.blocked_actions_hint'),
                'state' => $metrics['blocked_action_count'] > 0 ? 'warning' : 'success',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return list<string>
     */
    private function localeColumns(Collection $groups): array
    {
        $sourceLocales = $groups->pluck('source_locale')->filter()->values()->all();
        $existingLocales = $groups
            ->flatMap(fn (array $group): array => collect($group['locales'] ?? [])->pluck('locale')->all())
            ->filter()
            ->values()
            ->all();

        return collect($sourceLocales)
            ->merge($this->targetLocales())
            ->merge($existingLocales)
            ->filter()
            ->unique()
            ->sortBy(fn (string $locale): string => $locale === 'zh-CN' ? '0-'.$locale : '1-'.$locale)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function coverageMatrix(Collection $groups): array
    {
        $columns = $this->localeColumns($groups);

        return $groups
            ->map(fn (array $group): array => [
                'group_key' => (string) $group['group_key'],
                'content_type' => (string) $group['content_type'],
                'content_type_label' => (string) $group['content_type_label'],
                'slug' => (string) $group['slug'],
                'translation_group_id' => (string) $group['translation_group_id'],
                'source_locale' => (string) $group['source_locale'],
                'source_record_id' => $group['source_record_id'],
                'source_edit_url' => $group['source_edit_url'],
                'alerts' => $group['alerts'] ?? [],
                'health_state' => $this->groupHealthState($group),
                'health_label' => $this->groupHealthLabel($group),
                'cells' => collect($columns)
                    ->mapWithKeys(fn (string $locale): array => [$locale => $this->coverageCell($group, $locale)])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function coverageCell(array $group, string $locale): array
    {
        $localeRow = collect($group['locales'] ?? [])->first(
            fn (array $row): bool => (string) ($row['locale'] ?? '') === $locale
        );

        if (is_array($localeRow)) {
            $state = $this->localeCellState($localeRow);

            return [
                'locale' => $locale,
                'state' => $state,
                'status_state' => $this->localeCellStatusState($state),
                'status_label' => $this->localeCellStatusLabel($localeRow),
                'freshness_label' => (bool) ($localeRow['is_stale'] ?? false)
                    ? __('ops.translation_ops.matrix.stale')
                    : __('ops.translation_ops.matrix.current'),
                'freshness_state' => (bool) ($localeRow['is_stale'] ?? false) ? 'failed' : 'success',
                'publish_label' => (bool) ($localeRow['is_published'] ?? false)
                    ? __('ops.translation_ops.matrix.published')
                    : __('ops.translation_ops.matrix.not_published'),
                'publish_state' => (bool) ($localeRow['is_published'] ?? false) ? 'success' : 'gray',
                'record_label' => '#'.(string) ($localeRow['record_id'] ?? ''),
                'workflow_label' => (string) ($localeRow['workflow_kind_label'] ?? $localeRow['workflow_kind'] ?? ''),
                'ownership_blockers' => array_values(array_filter((array) ($localeRow['ownership_issues'] ?? []))),
                'readiness_blockers' => array_values(array_filter($this->localizedBlockers((array) data_get($localeRow, 'preflight.blockers', [])))),
                'blockers' => array_values(array_filter(array_merge(
                    (array) ($localeRow['ownership_issues'] ?? []),
                    (array) ($localeRow['readiness_blockers'] ?? []),
                ))),
                'actions' => $this->groupActionsByPriority((array) ($localeRow['actions'] ?? [])),
            ];
        }

        return [
            'locale' => $locale,
            'state' => 'missing',
            'status_state' => 'gray',
            'status_label' => __('ops.translation_ops.matrix.missing'),
            'freshness_label' => __('ops.translation_ops.matrix.not_available'),
            'freshness_state' => 'gray',
            'publish_label' => __('ops.translation_ops.matrix.not_published'),
            'publish_state' => 'gray',
            'record_label' => __('ops.translation_ops.fields.not_available'),
            'workflow_label' => __('ops.translation_ops.matrix.no_target_record'),
            'blockers' => [__('ops.translation_ops.alerts.missing_locale', ['locale' => $locale])],
            'actions' => $this->groupActionsByPriority($this->missingLocaleActions($group, $locale)),
        ];
    }

    private function localeCellState(array $locale): string
    {
        if ((bool) ($locale['is_source'] ?? false)) {
            return 'source';
        }

        if (! (bool) ($locale['ownership_ok'] ?? true) || ! (bool) data_get($locale, 'preflight.ok', true)) {
            return 'blocked';
        }

        if ((bool) ($locale['is_stale'] ?? false)) {
            return 'stale';
        }

        if ((bool) ($locale['is_published'] ?? false)) {
            return 'published';
        }

        return (string) ($locale['translation_status'] ?? 'draft');
    }

    private function localeCellStatusState(string $state): string
    {
        return match ($state) {
            'source', 'published' => 'success',
            'stale', 'blocked' => 'failed',
            'approved', 'human_review' => 'warning',
            default => $state,
        };
    }

    private function localeCellStatusLabel(array $locale): string
    {
        if ((bool) ($locale['is_source'] ?? false)) {
            return __('ops.translation_ops.matrix.source');
        }

        if ((bool) ($locale['is_stale'] ?? false)) {
            return __('ops.translation_ops.matrix.stale');
        }

        if ((bool) ($locale['is_published'] ?? false)) {
            return __('ops.translation_ops.matrix.published');
        }

        return StatusBadge::label((string) data_get($locale, 'translation_status', 'draft'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingLocaleActions(array $group, string $locale): array
    {
        $actions = collect($group['group_actions'] ?? [])
            ->filter(fn (array $action): bool => (string) ($action['target_locale'] ?? '') === $locale)
            ->values()
            ->all();

        if ($actions !== []) {
            return $actions;
        }

        return [[
            'label' => __('ops.translation_ops.actions.create_locale_translation_draft', ['locale' => $locale]),
            'enabled' => false,
            'reason' => __('ops.translation_ops.reasons.no_available_create_action'),
            'url' => null,
            'wire_action' => null,
            'content_type' => (string) ($group['content_type'] ?? ''),
            'record_id' => (int) ($group['source_record_id'] ?? 0),
            'target_locale' => $locale,
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return array{primary:list<array<string,mixed>>,secondary:list<array<string,mixed>>,disabled:list<array<string,mixed>>}
     */
    private function groupActionsByPriority(array $actions): array
    {
        $grouped = [
            'primary' => [],
            'secondary' => [],
            'disabled' => [],
        ];

        foreach ($actions as $action) {
            if (! (bool) ($action['enabled'] ?? false)) {
                $grouped['disabled'][] = $this->localizedAction($action);

                continue;
            }

            $wireAction = (string) ($action['wire_action'] ?? '');
            if (in_array($wireAction, ['createTranslationDraft', 'resyncFromSource', 'publishCurrentRevision'], true)) {
                $grouped['primary'][] = $this->localizedAction($action);

                continue;
            }

            $grouped['secondary'][] = $this->localizedAction($action);
        }

        return $grouped;
    }

    private function blockedActionCount(array $group): int
    {
        $groupActionCount = collect($group['group_actions'] ?? [])
            ->filter(fn (array $action): bool => ! (bool) ($action['enabled'] ?? false))
            ->count();
        $localeActionCount = collect($group['locales'] ?? [])
            ->sum(fn (array $locale): int => collect($locale['actions'] ?? [])
                ->filter(fn (array $action): bool => ! (bool) ($action['enabled'] ?? false))
                ->count());

        return $groupActionCount + $localeActionCount;
    }

    private function groupHealthState(array $group): string
    {
        if (! (bool) ($group['ownership_ok'] ?? true) || ! (bool) ($group['canonical_ok'] ?? true)) {
            return 'failed';
        }

        if ((int) ($group['stale_locales_count'] ?? 0) > 0 || ($group['coverage']['missing_target_locales'] ?? []) !== []) {
            return 'warning';
        }

        return 'success';
    }

    private function groupHealthLabel(array $group): string
    {
        return match ($this->groupHealthState($group)) {
            'failed' => __('ops.translation_ops.matrix.blocked'),
            'warning' => __('ops.translation_ops.matrix.needs_action'),
            default => __('ops.translation_ops.matrix.healthy'),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, list<string>>
     */
    private function filterOptions(Collection $groups): array
    {
        return [
            'content_types' => ['article', 'support_article', 'interpretation_guide', 'content_page'],
            'locales' => $groups->flatMap(fn (array $group): array => array_values(array_unique(array_merge(
                [$group['source_locale']],
                collect($group['locales'] ?? [])->pluck('locale')->all(),
            ))))->filter()->unique()->values()->all(),
            'statuses' => $groups->flatMap(fn (array $group): array => collect($group['locales'] ?? [])->pluck('translation_status')->all())
                ->filter()->unique()->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|Collection<int, array<string, mixed>>  $locales
     * @param  list<string>  $targetLocales
     * @return array<string, list<string>>
     */
    private function coverage(array|Collection $locales, array $targetLocales): array
    {
        $locales = collect($locales);
        $existing = $locales->pluck('locale')->all();
        $published = $locales->where('is_published', true)->pluck('locale')->all();
        $publishedTarget = array_values(array_intersect($targetLocales, $published));
        $machineDraft = $locales->where('translation_status', 'machine_draft')->pluck('locale')->all();
        $humanReview = $locales->where('translation_status', 'human_review')->pluck('locale')->all();
        $stale = $locales->where('is_stale', true)->pluck('locale')->all();
        $missing = array_values(array_diff($targetLocales, $locales->where('is_source', false)->pluck('locale')->all()));

        return [
            'target_locales' => $targetLocales,
            'existing_locales' => array_values(array_unique($existing)),
            'published_locales' => array_values(array_unique($published)),
            'published_target_locales' => $publishedTarget,
            'machine_draft_locales' => array_values(array_unique($machineDraft)),
            'human_review_locales' => array_values(array_unique($humanReview)),
            'stale_locales' => array_values(array_unique($stale)),
            'missing_target_locales' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return list<string>
     */
    private function publishedTargetLocales(array $coverage): array
    {
        return array_values(array_map(
            static fn (mixed $locale): string => (string) $locale,
            (array) ($coverage['published_target_locales'] ?? [])
        ));
    }

    /**
     * @return list<string>
     */
    private function targetLocales(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $locale): string => trim((string) $locale),
            (array) config('services.cms_translation.target_locales', ['en'])
        )));
    }

    private function contentTypeLabel(string $contentType): string
    {
        return match ($contentType) {
            'article' => __('ops.translation_ops.content_types.article'),
            'support_article' => __('ops.translation_ops.content_types.support_article'),
            'interpretation_guide' => __('ops.translation_ops.content_types.interpretation_guide'),
            'content_page' => __('ops.translation_ops.content_types.content_page'),
            default => ucfirst(str_replace('_', ' ', $contentType)),
        };
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    private function articleGroupActions(array $group): array
    {
        $actions = [];

        foreach (($group['actions'] ?? []) as $action) {
            $actions[] = [
                'label' => $action['label'],
                'enabled' => (bool) ($action['enabled'] ?? false),
                'reason' => $this->localizedReason($action['reason'] ?? null),
                'url' => $action['url'] ?? null,
                'wire_action' => $action['wire_action'] ?? null,
                'content_type' => 'article',
                'record_id' => (int) ($action['article_id'] ?? ($group['source_article_id'] ?? 0)),
                'target_locale' => $action['target_locale'] ?? null,
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $locale
     * @return list<array<string, mixed>>
     */
    private function articleLocaleActions(array $locale): array
    {
        return array_map(fn (array $action): array => [
            'label' => $action['label'],
            'enabled' => (bool) ($action['enabled'] ?? false),
            'reason' => $this->localizedReason($action['reason'] ?? null),
            'url' => $action['url'] ?? null,
            'wire_action' => $action['wire_action'] ?? null,
            'content_type' => 'article',
            'record_id' => (int) ($action['article_id'] ?? $locale['article_id']),
        ], $locale['actions'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siblingGroupActions(string $contentType, SiblingTranslationAdapter $adapter, ?Model $source, array $coverage): array
    {
        if (! $source instanceof Model) {
            return [];
        }

        $actions = [];
        foreach (($coverage['missing_target_locales'] ?? []) as $targetLocale) {
            $enabled = $this->siblingWorkflow->canGenerateMachineDraft($contentType);
            $actions[] = [
                'label' => __('ops.translation_ops.actions.create_locale_translation_draft', ['locale' => $targetLocale]),
                'enabled' => $enabled,
                'reason' => $enabled ? null : $this->localizedReason($this->siblingWorkflow->machineDraftUnavailableReason($contentType)),
                'url' => null,
                'wire_action' => 'createTranslationDraft',
                'content_type' => $contentType,
                'record_id' => (int) $source->id,
                'target_locale' => $targetLocale,
            ];
        }

        $actions[] = [
            'label' => __('ops.translation_ops.actions.open_source_type', ['content_type' => $this->contentTypeLabel($contentType)]),
            'enabled' => true,
            'reason' => null,
            'url' => $adapter->editUrl($source),
            'wire_action' => null,
            'content_type' => $contentType,
            'record_id' => (int) $source->id,
            'target_locale' => null,
        ];

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siblingLocaleActions(string $contentType, SiblingTranslationAdapter $adapter, Model $record, bool $isSource, bool $isStale): array
    {
        if ($isSource) {
            return [[
                'label' => __('ops.translation_ops.actions.open_source'),
                'enabled' => true,
                'reason' => null,
                'url' => $adapter->editUrl($record),
                'wire_action' => null,
                'content_type' => $contentType,
                'record_id' => (int) $record->id,
            ]];
        }

        $actions = [[
            'label' => __('ops.translation_ops.actions.open_target'),
            'enabled' => true,
            'reason' => null,
            'url' => $adapter->editUrl($record),
            'wire_action' => null,
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ]];

        $canResync = $this->siblingWorkflow->canGenerateMachineDraft($contentType) && (! $adapter->isPublished($record) || $adapter->supportsPublishedResync());
        $actions[] = [
            'label' => __('ops.translation_ops.actions.resync_from_source'),
            'enabled' => $isStale && $canResync,
            'reason' => $isStale
                ? ($canResync ? null : ($adapter->isPublished($record) ? __('ops.translation_ops.reasons.published_row_backed_resync_disabled') : $this->siblingWorkflow->machineDraftUnavailableReason($contentType)))
                : __('ops.translation_ops.reasons.target_not_stale'),
            'url' => null,
            'wire_action' => 'resyncFromSource',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];

        $status = (string) $record->translation_status;
        $actions[] = [
            'label' => __('ops.translation_ops.actions.promote_to_human_review'),
            'enabled' => in_array($status, ['draft', 'machine_draft'], true),
            'reason' => in_array($status, ['draft', 'machine_draft'], true) ? null : __('ops.translation_ops.reasons.only_machine_draft_promote'),
            'url' => null,
            'wire_action' => 'promoteToHumanReview',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => __('ops.translation_ops.actions.approve_translation'),
            'enabled' => $status === 'human_review',
            'reason' => $status === 'human_review' ? null : __('ops.translation_ops.reasons.only_human_review_approve'),
            'url' => null,
            'wire_action' => 'approveTranslation',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => __('ops.translation_ops.actions.publish_translation'),
            'enabled' => $status === 'approved',
            'reason' => $status === 'approved' ? null : __('ops.translation_ops.reasons.only_approved_publish'),
            'url' => null,
            'wire_action' => 'publishCurrentRevision',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => __('ops.translation_ops.actions.archive_stale'),
            'enabled' => $isStale && ! $adapter->isPublished($record),
            'reason' => $isStale
                ? ($adapter->isPublished($record) ? __('ops.translation_ops.reasons.published_rows_cannot_archive') : null)
                : __('ops.translation_ops.reasons.only_stale_archive'),
            'url' => null,
            'wire_action' => 'archiveStaleRevision',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function localizedPreflight(array $preflight): array
    {
        $preflight['blockers'] = $this->localizedBlockers((array) ($preflight['blockers'] ?? []));

        return $preflight;
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function localizedBlockers(array $blockers): array
    {
        return array_values(array_map(fn (string $blocker): string => $this->localizedBlocker($blocker), $blockers));
    }

    private function localizedBlocker(string $blocker): string
    {
        $key = str_replace(['/', ' '], ['_', '_'], strtolower(trim($blocker)));
        $translationKey = "ops.translation_ops.blockers.{$key}";

        return Lang::has($translationKey) ? (string) __($translationKey) : $blocker;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function localizedAction(array $action): array
    {
        $action['reason'] = $this->localizedReason($action['reason'] ?? null);

        return $action;
    }

    private function localizedReason(mixed $reason): ?string
    {
        $raw = trim((string) $reason);
        if ($raw === '') {
            return null;
        }

        if ($raw === 'machine translation provider unavailable') {
            return __('ops.translation_ops.reasons.machine_translation_provider_unavailable');
        }

        if ($raw === 'This content type does not support machine draft creation in the current backend contract.') {
            return __('ops.translation_ops.reasons.machine_draft_creation_not_supported');
        }

        if (preg_match('/^Machine translation provider is not configured for ([a-z_]+)\\./', $raw, $matches) === 1) {
            return __('ops.translation_ops.reasons.cms_machine_translation_provider_unconfigured_for_type', [
                'content_type' => $this->contentTypeLabel($matches[1]),
            ]);
        }

        if (preg_match('/^Provider does not support ([a-z_]+)\\.$/', $raw, $matches) === 1) {
            return __('ops.translation_ops.reasons.cms_machine_translation_provider_not_supported_for_type', [
                'content_type' => $this->contentTypeLabel($matches[1]),
            ]);
        }

        if (str_contains($raw, 'CMS machine translation provider is not configured. Set CMS_TRANSLATION_OPENAI_API_KEY')) {
            return __('ops.translation_ops.reasons.cms_machine_translation_provider_missing_api_key');
        }

        if (str_contains($raw, 'CMS machine translation provider is not configured. Set CMS_TRANSLATION_OPENAI_MODEL')) {
            return __('ops.translation_ops.reasons.cms_machine_translation_provider_missing_model');
        }

        if (str_contains($raw, 'Machine translation provider is not configured')) {
            return __('ops.translation_ops.reasons.machine_translation_provider_unconfigured');
        }

        foreach ($this->rawBlockerPhrases() as $phrase) {
            if (str_contains($raw, $phrase)) {
                $raw = str_replace($phrase, $this->localizedBlocker($phrase), $raw);
            }
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function rawBlockerPhrases(): array
    {
        return [
            'target article is source',
            'target article org mismatch',
            'target locale missing',
            'working revision missing',
            'working revision is stale',
            'working revision is archived',
            'source canonical invalid',
            'source_article_id mismatch',
            'source_locale mismatch',
            'translation_group mismatch',
            'references/citations presence check failed',
            'seo_meta org mismatch',
            'working revision org mismatch',
            'working revision article mismatch',
            'working revision locale mismatch',
            'working revision group mismatch',
            'target row is source',
            'target row org mismatch',
            'working revision missing payload',
            'source linkage invalid',
            'source_content_id mismatch',
            'target translation is stale',
            'title missing',
            'body missing',
            'seo title missing',
            'seo description missing',
        ];
    }
}
