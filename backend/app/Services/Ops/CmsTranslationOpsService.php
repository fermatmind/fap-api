<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Contracts\Cms\SiblingTranslationAdapter;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
                'content_type_label' => 'Articles',
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
                    'preflight' => $locale['preflight'],
                    'compare_summary' => $locale['compare_summary'],
                    'workflow_kind' => 'revision',
                    'actions' => $this->articleLocaleActions($locale),
                ], $group['locales'] ?? []),
                'published_locales' => $group['published_locales'] ?? [],
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
                $alerts[] = ['label' => 'missing '.$locale.' locale'];
            }
        }
        if ($locales->contains(fn (array $locale): bool => (bool) $locale['is_stale'])) {
            $alerts[] = ['label' => 'source updated after target review'];
        }
        if ($locales->contains(fn (array $locale): bool => ! (bool) $locale['ownership_ok'])) {
            $alerts[] = ['label' => 'ownership mismatch'];
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
        $ownershipIssues = [];

        if ((int) $record->org_id !== 0) {
            $ownershipIssues[] = 'row org mismatch';
        }
        if (! $isSource && ! filled($record->source_content_id)) {
            $ownershipIssues[] = 'source linkage missing';
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
            'working_revision_id' => null,
            'published_revision_id' => null,
            'source_version_hash' => $record->source_version_hash,
            'translated_from_version_hash' => $record->translated_from_version_hash,
            'ownership_ok' => $ownershipIssues === [],
            'ownership_issues' => $ownershipIssues,
            'edit_url' => $adapter->editUrl($record),
            'preflight' => $preflight,
            'compare_summary' => [
                'row-backed workflow',
                $isStale ? 'source hash drift detected' : 'source hash aligned',
            ],
            'workflow_kind' => 'row',
            'actions' => $this->siblingLocaleActions($contentType, $adapter, $record, $isSource, $isStale),
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
        return [
            'translation_groups' => $groups->count(),
            'stale_groups' => $groups->where('stale_locales_count', '>', 0)->count(),
            'published_groups' => $groups->filter(fn (array $group): bool => ($group['published_locales'] ?? []) !== [])->count(),
            'missing_target_locale' => $groups->filter(fn (array $group): bool => ($group['coverage']['missing_target_locales'] ?? []) !== [])->count(),
            'ownership_mismatch_groups' => $groups->where('ownership_ok', false)->count(),
            'canonical_risk_groups' => $groups->where('canonical_ok', false)->count(),
        ];
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
        $machineDraft = $locales->where('translation_status', 'machine_draft')->pluck('locale')->all();
        $humanReview = $locales->where('translation_status', 'human_review')->pluck('locale')->all();
        $stale = $locales->where('is_stale', true)->pluck('locale')->all();
        $missing = array_values(array_diff($targetLocales, $locales->where('is_source', false)->pluck('locale')->all()));

        return [
            'target_locales' => $targetLocales,
            'existing_locales' => array_values(array_unique($existing)),
            'published_locales' => array_values(array_unique($published)),
            'machine_draft_locales' => array_values(array_unique($machineDraft)),
            'human_review_locales' => array_values(array_unique($humanReview)),
            'stale_locales' => array_values(array_unique($stale)),
            'missing_target_locales' => $missing,
        ];
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
            'article' => 'Articles',
            'support_article' => 'Support Articles',
            'interpretation_guide' => 'Interpretation Guides',
            'content_page' => 'Content Pages',
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
                'reason' => $action['reason'] ?? null,
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
            'reason' => $action['reason'] ?? null,
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
                'label' => 'Create '.$targetLocale.' translation draft',
                'enabled' => $enabled,
                'reason' => $enabled ? null : $this->siblingWorkflow->machineDraftUnavailableReason($contentType),
                'url' => null,
                'wire_action' => 'createTranslationDraft',
                'content_type' => $contentType,
                'record_id' => (int) $source->id,
                'target_locale' => $targetLocale,
            ];
        }

        $actions[] = [
            'label' => 'Open source '.$this->contentTypeLabel($contentType),
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
                'label' => 'Open source',
                'enabled' => true,
                'reason' => null,
                'url' => $adapter->editUrl($record),
                'wire_action' => null,
                'content_type' => $contentType,
                'record_id' => (int) $record->id,
            ]];
        }

        $actions = [[
            'label' => 'Open target',
            'enabled' => true,
            'reason' => null,
            'url' => $adapter->editUrl($record),
            'wire_action' => null,
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ]];

        $canResync = $this->siblingWorkflow->canGenerateMachineDraft($contentType) && (! $adapter->isPublished($record) || $adapter->supportsPublishedResync());
        $actions[] = [
            'label' => 'Re-sync from source',
            'enabled' => $isStale && $canResync,
            'reason' => $isStale
                ? ($canResync ? null : ($adapter->isPublished($record) ? 'Published row-backed translations cannot be re-synced safely yet.' : $this->siblingWorkflow->machineDraftUnavailableReason($contentType)))
                : 'Target is not stale',
            'url' => null,
            'wire_action' => 'resyncFromSource',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];

        $status = (string) $record->translation_status;
        $actions[] = [
            'label' => 'Promote to human review',
            'enabled' => in_array($status, ['draft', 'machine_draft'], true),
            'reason' => in_array($status, ['draft', 'machine_draft'], true) ? null : 'Only draft or machine_draft rows can be promoted.',
            'url' => null,
            'wire_action' => 'promoteToHumanReview',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => 'Approve translation',
            'enabled' => $status === 'human_review',
            'reason' => $status === 'human_review' ? null : 'Only human_review rows can be approved.',
            'url' => null,
            'wire_action' => 'approveTranslation',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => 'Publish translation',
            'enabled' => $status === 'approved',
            'reason' => $status === 'approved' ? null : 'Only approved rows can be published.',
            'url' => null,
            'wire_action' => 'publishCurrentRevision',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];
        $actions[] = [
            'label' => 'Archive stale',
            'enabled' => $isStale && ! $adapter->isPublished($record),
            'reason' => $isStale
                ? ($adapter->isPublished($record) ? 'Published rows cannot be archived here.' : null)
                : 'Only stale translation rows can be archived.',
            'url' => null,
            'wire_action' => 'archiveStaleRevision',
            'content_type' => $contentType,
            'record_id' => (int) $record->id,
        ];

        return $actions;
    }
}
