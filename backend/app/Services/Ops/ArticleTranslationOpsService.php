<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleTranslationWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final class ArticleTranslationOpsService
{
    private const PUBLIC_ARTICLE_ORG_ID = 0;

    private const DEFAULT_TARGET_LOCALE = 'en';

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     metrics: array<string, int>,
     *     groups: list<array<string, mixed>>,
     *     selected_group: array<string, mixed>|null,
     *     filter_options: array<string, list<string>>
     * }
     */
    public function dashboard(array $filters = [], ?string $selectedGroupId = null): array
    {
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
            ->whereNotNull('translation_group_id')
            ->orderBy('translation_group_id')
            ->orderBy('locale')
            ->get()
            ->groupBy(fn (Article $article): string => (string) $article->translation_group_id)
            ->filter(fn (Collection $groupArticles): bool => $this->belongsToPublicArticleSurface($groupArticles))
            ->flatten(1)
            ->values();

        $groupIds = $articles
            ->pluck('translation_group_id')
            ->filter()
            ->map(fn (mixed $groupId): string => (string) $groupId)
            ->unique()
            ->values();

        $revisionsByGroup = $groupIds->isEmpty()
            ? collect()
            : ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->whereIn('translation_group_id', $groupIds->all())
                ->orderByDesc('id')
                ->get()
                ->groupBy(fn (ArticleTranslationRevision $revision): string => (string) $revision->translation_group_id);

        $groups = $articles
            ->groupBy(fn (Article $article): string => (string) $article->translation_group_id)
            ->map(fn (Collection $groupArticles, string $groupId): array => $this->summarizeGroup(
                $groupId,
                $groupArticles,
                $revisionsByGroup->get($groupId, collect())
            ))
            ->sortBy('slug')
            ->values();

        $filteredGroups = $this->applyFilters($groups, $filters)->values();

        return [
            'metrics' => $this->metrics($groups, $filters),
            'groups' => $filteredGroups->all(),
            'selected_group' => $this->selectedGroup($groups, $selectedGroupId),
            'filter_options' => $this->filterOptions($articles),
        ];
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @param  Collection<int, ArticleTranslationRevision>  $revisions
     * @return array<string, mixed>
     */
    private function summarizeGroup(string $groupId, Collection $articles, Collection $revisions): array
    {
        $sourceArticles = $articles->filter(fn (Article $article): bool => $this->isSource($article));
        $source = $this->selectSourceArticle($sourceArticles, $articles);
        $sourceHash = $this->sourceHash($source);
        $articleIds = $articles->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $orphanRevisions = $revisions->filter(
            fn (ArticleTranslationRevision $revision): bool => ! in_array((int) $revision->article_id, $articleIds, true)
        );

        $locales = $articles
            ->sortBy(fn (Article $article): string => ($this->isSource($article) ? '0-' : '1-').(string) $article->locale)
            ->map(fn (Article $article): array => $this->summarizeLocale($article, $source, $sourceHash, $revisions))
            ->values();
        $coverage = $this->coverage($locales, $this->targetLocales());

        $staleLocales = $locales->filter(fn (array $locale): bool => (bool) $locale['is_stale']);
        $publishedLocales = $locales->filter(fn (array $locale): bool => (bool) $locale['is_published']);
        $ownershipIssues = $locales
            ->flatMap(fn (array $locale): array => $locale['ownership_issues'])
            ->merge($orphanRevisions->isNotEmpty() ? ['orphan revision'] : [])
            ->values()
            ->all();
        $canonicalIssues = $this->canonicalIssues($sourceArticles, $articles, $source);
        $alerts = $this->alerts($locales, $canonicalIssues, $ownershipIssues);

        return [
            'translation_group_id' => $groupId,
            'slug' => (string) ($source?->slug ?? $articles->first()?->slug ?? ''),
            'source_locale' => (string) ($source?->locale ?? ''),
            'source_article_id' => $source?->id ? (int) $source->id : null,
            'source_article_status' => (string) ($source?->status ?? 'missing'),
            'source_edit_url' => $source instanceof Article ? ArticleResource::getUrl('edit', ['record' => $source]) : null,
            'latest_source_hash' => $sourceHash,
            'locales' => $locales->all(),
            'locale_codes' => $locales->pluck('locale')->all(),
            'published_locales' => $publishedLocales->pluck('locale')->all(),
            'coverage' => $coverage,
            'stale_locales_count' => $staleLocales->count(),
            'has_working_revision' => $locales->contains(fn (array $locale): bool => (bool) $locale['working_revision_id']),
            'has_published_revision' => $locales->contains(fn (array $locale): bool => (bool) $locale['published_revision_id']),
            'ownership_ok' => empty($ownershipIssues),
            'ownership_issues' => array_values(array_unique($ownershipIssues)),
            'canonical_ok' => empty($canonicalIssues),
            'canonical_issues' => $canonicalIssues,
            'orphan_revision_count' => $orphanRevisions->count(),
            'alerts' => $alerts,
            'revision_history' => $this->revisionHistory($revisions),
            'actions' => $this->groupActions($source, $coverage['missing_target_locales']),
        ];
    }

    /**
     * @param  Collection<int, ArticleTranslationRevision>  $revisions
     * @return array<string, mixed>
     */
    private function summarizeLocale(Article $article, ?Article $source, ?string $sourceHash, Collection $revisions): array
    {
        $workingRevision = $article->workingRevision;
        $publishedRevision = $article->publishedRevision;
        $status = (string) ($workingRevision?->revision_status ?? $article->translation_status ?? Article::TRANSLATION_STATUS_SOURCE);
        $translatedFromHash = $workingRevision?->translated_from_version_hash ?: $article->translated_from_version_hash;
        $isSource = $source instanceof Article && (int) $article->id === (int) $source->id;
        $isStale = ! $isSource
            && filled($sourceHash)
            && filled($translatedFromHash)
            && ! hash_equals((string) $sourceHash, (string) $translatedFromHash);
        $isArticlePublishedPublic = (string) $article->status === 'published' && (bool) $article->is_public;
        $isPublished = $isArticlePublishedPublic && $publishedRevision instanceof ArticleTranslationRevision;
        $ownershipIssues = $this->ownershipIssues($article);
        $articleRevisions = $revisions
            ->filter(fn (ArticleTranslationRevision $revision): bool => (int) $revision->article_id === (int) $article->id)
            ->take(3);

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'is_source' => $isSource,
            'translation_status' => $status,
            'article_status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_article_published_public' => $isArticlePublishedPublic,
            'is_published' => $isPublished,
            'is_stale' => $isStale || $status === Article::TRANSLATION_STATUS_STALE,
            'published_at' => $article->published_at?->toIso8601String(),
            'source_article_id' => $article->source_article_id ? (int) $article->source_article_id : null,
            'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
            'working_revision_status' => $workingRevision?->revision_status,
            'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
            'published_revision_status' => $publishedRevision?->revision_status,
            'source_version_hash' => $workingRevision?->source_version_hash ?: $article->source_version_hash,
            'translated_from_version_hash' => $translatedFromHash,
            'ownership_ok' => empty($ownershipIssues),
            'ownership_issues' => $ownershipIssues,
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
            'revision_history' => $this->revisionHistory($articleRevisions),
            'compare_summary' => $this->compareSummary($article, $source, $workingRevision, $publishedRevision, $sourceHash, $isStale),
            'preflight' => $isSource
                ? ['ok' => true, 'blockers' => []]
                : $this->localizedPreflight(app(ArticleTranslationWorkflowService::class)->preflight($article)),
            'actions' => $this->localeActions($article, $status, $isStale, $isSource),
        ];
    }

    /**
     * @return list<string>
     */
    private function ownershipIssues(Article $article): array
    {
        $issues = [];

        if ((int) $article->org_id !== self::PUBLIC_ARTICLE_ORG_ID) {
            $issues[] = __('ops.translation_ops.ownership_issues.article_org_mismatch');
        }

        $workingRevision = $article->workingRevision;
        if ($workingRevision instanceof ArticleTranslationRevision) {
            $issues = array_merge($issues, $this->revisionOwnershipIssues($article, $workingRevision, 'working revision'));
        }

        $publishedRevision = $article->publishedRevision;
        if ($publishedRevision instanceof ArticleTranslationRevision) {
            $issues = array_merge($issues, $this->revisionOwnershipIssues($article, $publishedRevision, 'published revision'));
        }

        $seoMeta = $article->seoMeta;
        if ($seoMeta instanceof ArticleSeoMeta) {
            if ((int) $seoMeta->org_id !== self::PUBLIC_ARTICLE_ORG_ID
                || (int) $seoMeta->org_id !== (int) $article->org_id) {
                $issues[] = __('ops.translation_ops.ownership_issues.seo_meta_org_mismatch');
            }
            if ((int) $seoMeta->article_id !== (int) $article->id) {
                $issues[] = __('ops.translation_ops.ownership_issues.seo_meta_article_mismatch');
            }
            if ((string) $seoMeta->locale !== (string) $article->locale) {
                $issues[] = __('ops.translation_ops.ownership_issues.seo_meta_locale_mismatch');
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return list<string>
     */
    private function revisionOwnershipIssues(Article $article, ArticleTranslationRevision $revision, string $label): array
    {
        $issues = [];
        $labelKey = str_replace(' ', '_', $label);

        if ((int) $revision->org_id !== self::PUBLIC_ARTICLE_ORG_ID) {
            $issues[] = __("ops.translation_ops.ownership_issues.{$labelKey}_org_mismatch");
        } elseif ((int) $revision->org_id !== (int) $article->org_id) {
            $issues[] = __("ops.translation_ops.ownership_issues.{$labelKey}_org_mismatch");
        }
        if ((int) $revision->article_id !== (int) $article->id) {
            $issues[] = __("ops.translation_ops.ownership_issues.{$labelKey}_article_mismatch");
        }
        if ((string) $revision->locale !== (string) $article->locale) {
            $issues[] = __("ops.translation_ops.ownership_issues.{$labelKey}_locale_mismatch");
        }
        if ((string) $revision->translation_group_id !== (string) $article->translation_group_id) {
            $issues[] = __("ops.translation_ops.ownership_issues.{$labelKey}_group_mismatch");
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Article>  $sourceArticles
     * @param  Collection<int, Article>  $articles
     * @return list<string>
     */
    private function canonicalIssues(Collection $sourceArticles, Collection $articles, ?Article $source): array
    {
        $issues = [];

        if ($sourceArticles->count() !== 1) {
            $issues[] = __('ops.translation_ops.ownership_issues.source_article_count', ['count' => $sourceArticles->count()]);
        }

        if (! $source instanceof Article) {
            return $issues;
        }

        foreach ($articles as $article) {
            if ((int) $article->id === (int) $source->id) {
                continue;
            }

            if ((int) ($article->source_article_id ?? 0) !== (int) $source->id) {
                $issues[] = __('ops.translation_ops.ownership_issues.translation_source_article_id_mismatch');
            }
            if ((string) $article->source_locale !== (string) $source->locale) {
                $issues[] = __('ops.translation_ops.ownership_issues.translation_source_locale_mismatch');
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $locales
     * @param  list<string>  $canonicalIssues
     * @param  list<string>  $ownershipIssues
     * @return list<array{label:string,state:string}>
     */
    private function alerts(Collection $locales, array $canonicalIssues, array $ownershipIssues): array
    {
        $alerts = [];

        if ($locales->contains(fn (array $locale): bool => (bool) $locale['is_stale'] && (bool) $locale['is_published'])) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.stale_published_translation'), 'state' => 'failed'];
        }
        if ($locales->contains(fn (array $locale): bool => (bool) $locale['is_stale'])) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.source_updated_after_target_review'), 'state' => 'warning'];
        }
        if ($locales->contains(
            fn (array $locale): bool => (bool) $locale['is_article_published_public'] && empty($locale['published_revision_id'])
        )) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.missing_published_revision'), 'state' => 'failed'];
        }
        $missingTargetLocales = array_values(array_diff($this->targetLocales(), $locales->pluck('locale')->all()));
        foreach ($missingTargetLocales as $missingLocale) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.missing_locale', ['locale' => $missingLocale]), 'state' => 'warning'];
        }
        if (! empty($ownershipIssues)) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.ownership_mismatch'), 'state' => 'failed'];
        }
        if (! empty($canonicalIssues)) {
            $alerts[] = ['label' => __('ops.translation_ops.alerts.canonical_source_split_risk'), 'state' => 'failed'];
        }

        return $alerts;
    }

    /**
     * @param  Collection<int, ArticleTranslationRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    private function revisionHistory(Collection $revisions): array
    {
        return $revisions
            ->take(3)
            ->map(fn (ArticleTranslationRevision $revision): array => [
                'id' => (int) $revision->id,
                'article_id' => (int) $revision->article_id,
                'locale' => (string) $revision->locale,
                'revision_number' => (int) $revision->revision_number,
                'revision_status' => (string) $revision->revision_status,
                'source_version_hash' => $this->shortHash($revision->source_version_hash),
                'translated_from_version_hash' => $this->shortHash($revision->translated_from_version_hash),
                'updated_at' => $revision->updated_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $missingTargetLocales
     * @return list<array<string, mixed>>
     */
    private function groupActions(?Article $source, array $missingTargetLocales): array
    {
        $workflow = app(ArticleTranslationWorkflowService::class);
        $defaultTargetLocale = in_array(self::DEFAULT_TARGET_LOCALE, $missingTargetLocales, true)
            ? self::DEFAULT_TARGET_LOCALE
            : ($missingTargetLocales[0] ?? self::DEFAULT_TARGET_LOCALE);
        $canCreate = $source instanceof Article
            && ContentAccess::canWrite()
            && in_array($defaultTargetLocale, $missingTargetLocales, true)
            && $workflow->canGenerateMachineDraft();

        return [
            [
                'label' => __('ops.translation_ops.actions.open_source_article'),
                'enabled' => $source instanceof Article,
                'url' => $source instanceof Article ? ArticleResource::getUrl('edit', ['record' => $source]) : null,
                'reason' => null,
            ],
            [
                'label' => __('ops.translation_ops.actions.create_translation_draft'),
                'enabled' => $canCreate,
                'wire_action' => 'createTranslationDraft',
                'article_id' => $source instanceof Article ? (int) $source->id : null,
                'target_locale' => $defaultTargetLocale,
                'url' => null,
                'reason' => $canCreate
                    ? null
                    : ($workflow->canGenerateMachineDraft()
                        ? __('ops.translation_ops.reasons.target_locale_exists_or_missing_permission')
                        : $this->localizedReason($workflow->machineDraftUnavailableReason())),
            ],
            [
                'label' => __('ops.translation_ops.actions.resync_from_source'),
                'enabled' => false,
                'url' => null,
                'reason' => __('ops.translation_ops.reasons.use_per_locale_action'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localeActions(Article $article, string $status, bool $isStale, bool $isSource): array
    {
        $workflow = app(ArticleTranslationWorkflowService::class);
        $preflight = $isSource ? ['ok' => true, 'blockers' => []] : $this->localizedPreflight($workflow->preflight($article));

        return [
            [
                'label' => __('ops.translation_ops.actions.open_target_article'),
                'enabled' => true,
                'url' => ArticleResource::getUrl('edit', ['record' => $article]),
                'reason' => null,
            ],
            [
                'label' => __('ops.translation_ops.actions.promote_to_human_review'),
                'enabled' => ContentAccess::canWrite()
                    && ! $isSource
                    && $status === Article::TRANSLATION_STATUS_MACHINE_DRAFT
                    && ! $isStale,
                'wire_action' => 'promoteToHumanReview',
                'article_id' => (int) $article->id,
                'reason' => $status === Article::TRANSLATION_STATUS_MACHINE_DRAFT
                    ? __('ops.translation_ops.reasons.requires_write_current_revision')
                    : __('ops.translation_ops.reasons.only_machine_draft_promote'),
            ],
            [
                'label' => __('ops.translation_ops.actions.publish_current_working_revision'),
                'enabled' => ContentAccess::canRelease()
                    && ! $isSource
                    && ! $isStale
                    && $article->status !== 'published'
                    && $article->workingRevision instanceof ArticleTranslationRevision
                    && $article->workingRevision->revision_status === ArticleTranslationRevision::STATUS_APPROVED
                    && (bool) $preflight['ok'],
                'wire_action' => 'publishCurrentRevision',
                'article_id' => (int) $article->id,
                'reason' => (bool) $preflight['ok']
                    ? __('ops.translation_ops.reasons.uses_translation_preflight')
                    : __('ops.translation_ops.reasons.preflight_blocked', ['blockers' => implode('; ', $preflight['blockers'])]),
            ],
            [
                'label' => __('ops.translation_ops.actions.approve_translation'),
                'enabled' => ContentAccess::canReview()
                    && ! $isSource
                    && ! $isStale
                    && $article->workingRevision instanceof ArticleTranslationRevision
                    && $article->workingRevision->revision_status === ArticleTranslationRevision::STATUS_HUMAN_REVIEW
                    && (bool) $preflight['ok'],
                'wire_action' => 'approveTranslation',
                'article_id' => (int) $article->id,
                'reason' => (bool) $preflight['ok']
                    ? __('ops.translation_ops.reasons.only_human_review_approve')
                    : __('ops.translation_ops.reasons.preflight_blocked', ['blockers' => implode('; ', $preflight['blockers'])]),
            ],
            [
                'label' => __('ops.translation_ops.actions.resync_from_source'),
                'enabled' => ContentAccess::canWrite()
                    && ! $isSource
                    && $isStale
                    && $workflow->canGenerateMachineDraft(),
                'wire_action' => 'resyncFromSource',
                'article_id' => (int) $article->id,
                'reason' => $workflow->canGenerateMachineDraft()
                    ? __('ops.translation_ops.reasons.only_stale_resync')
                    : $this->localizedReason($workflow->machineDraftUnavailableReason()),
            ],
            [
                'label' => __('ops.translation_ops.actions.archive_stale_revision'),
                'enabled' => ContentAccess::canWrite()
                    && ! $isSource
                    && $isStale
                    && $article->workingRevision instanceof ArticleTranslationRevision
                    && (int) $article->working_revision_id !== (int) $article->published_revision_id,
                'wire_action' => 'archiveStaleRevision',
                'article_id' => (int) $article->id,
                'reason' => __('ops.translation_ops.reasons.only_non_published_stale_archive'),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $locales
     * @return array<string, mixed>
     */
    private function coverage(Collection $locales, array $targetLocales): array
    {
        $localeCodes = $locales->pluck('locale')->all();
        $missingTargetLocales = array_values(array_diff($targetLocales, $localeCodes));
        $sourceLocale = $locales->first(fn (array $locale): bool => (bool) $locale['is_source']);

        return [
            'source_locale' => (string) ($sourceLocale['locale'] ?? ''),
            'target_locales' => $targetLocales,
            'existing_locales' => $localeCodes,
            'published_locales' => $locales->filter(fn (array $locale): bool => (bool) $locale['is_published'])->pluck('locale')->all(),
            'machine_draft_locales' => $locales->filter(fn (array $locale): bool => $locale['translation_status'] === Article::TRANSLATION_STATUS_MACHINE_DRAFT)->pluck('locale')->all(),
            'human_review_locales' => $locales->filter(fn (array $locale): bool => $locale['translation_status'] === Article::TRANSLATION_STATUS_HUMAN_REVIEW)->pluck('locale')->all(),
            'stale_locales' => $locales->filter(fn (array $locale): bool => (bool) $locale['is_stale'])->pluck('locale')->all(),
            'missing_target_locales' => $missingTargetLocales,
        ];
    }

    /**
     * @return list<string>
     */
    private function compareSummary(
        Article $article,
        ?Article $source,
        ?ArticleTranslationRevision $workingRevision,
        ?ArticleTranslationRevision $publishedRevision,
        ?string $sourceHash,
        bool $isStale
    ): array {
        $summary = [];
        $summary[] = __('ops.translation_ops.compare.source_hash', ['hash' => $this->shortHash($sourceHash) ?? __('ops.status.missing')]);
        $summary[] = __('ops.translation_ops.compare.translated_from', ['hash' => $this->shortHash($workingRevision?->translated_from_version_hash) ?? __('ops.status.missing')]);
        $summary[] = $isStale
            ? __('ops.translation_ops.compare.source_changed_after_target_revision')
            : __('ops.translation_ops.compare.source_target_hash_current');

        if ($workingRevision instanceof ArticleTranslationRevision && $publishedRevision instanceof ArticleTranslationRevision) {
            $summary[] = (int) $workingRevision->id === (int) $publishedRevision->id
                ? __('ops.translation_ops.compare.working_revision_is_published_revision')
                : __('ops.translation_ops.compare.working_revision_differs_from_published_revision');
        } elseif ($workingRevision instanceof ArticleTranslationRevision) {
            $summary[] = __('ops.translation_ops.compare.working_revision_exists_no_published_revision');
        } else {
            $summary[] = __('ops.translation_ops.compare.working_revision_missing');
        }

        if ($source instanceof Article) {
            $summary[] = __('ops.translation_ops.compare.source_to_target', [
                'source' => $source->id,
                'locale' => $article->locale,
                'target' => $article->id,
            ]);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $groups, array $filters): Collection
    {
        $slug = Str::lower(trim((string) ($filters['slug'] ?? '')));
        $sourceLocale = (string) ($filters['source_locale'] ?? 'all');
        $targetLocale = (string) ($filters['target_locale'] ?? 'all');
        $status = (string) ($filters['translation_status'] ?? 'all');
        $stale = (string) ($filters['stale'] ?? 'all');
        $published = (string) ($filters['published'] ?? 'all');
        $missingLocale = (bool) ($filters['missing_locale'] ?? false);
        $ownership = (string) ($filters['ownership'] ?? 'all');
        $missingLocaleTarget = $targetLocale === 'all' ? self::DEFAULT_TARGET_LOCALE : $targetLocale;

        return $groups->filter(function (array $group) use (
            $slug,
            $sourceLocale,
            $targetLocale,
            $status,
            $stale,
            $published,
            $missingLocale,
            $ownership,
            $missingLocaleTarget
        ): bool {
            $locales = collect($group['locales']);

            if ($slug !== '' && ! str_contains(Str::lower((string) $group['slug']), $slug)) {
                return false;
            }
            if ($sourceLocale !== 'all' && (string) $group['source_locale'] !== $sourceLocale) {
                return false;
            }
            if (
                $targetLocale !== 'all'
                && ! $missingLocale
                && $published !== 'unpublished'
                && ! in_array($targetLocale, $group['locale_codes'], true)
            ) {
                return false;
            }
            if ($status !== 'all' && ! $locales->contains(fn (array $locale): bool => (string) $locale['translation_status'] === $status)) {
                return false;
            }
            if ($stale === 'stale' && (int) $group['stale_locales_count'] === 0) {
                return false;
            }
            if ($stale === 'current' && (int) $group['stale_locales_count'] > 0) {
                return false;
            }
            $publicationLocales = $targetLocale === 'all'
                ? $locales
                : $locales->filter(fn (array $locale): bool => (string) $locale['locale'] === $targetLocale);
            $hasPublishedLocale = $publicationLocales->contains(fn (array $locale): bool => (bool) $locale['is_published']);

            if ($published === 'published' && ! $hasPublishedLocale) {
                return false;
            }
            if ($published === 'unpublished' && $hasPublishedLocale) {
                return false;
            }
            if ($missingLocale && in_array($missingLocaleTarget, $group['locale_codes'], true)) {
                return false;
            }
            if ($ownership === 'mismatch' && (bool) $group['ownership_ok']) {
                return false;
            }
            if ($ownership === 'ok' && ! (bool) $group['ownership_ok']) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function metrics(Collection $groups, array $filters): array
    {
        $targetLocale = (string) ($filters['target_locale'] ?? self::DEFAULT_TARGET_LOCALE);
        $targetLocale = $targetLocale === 'all' ? self::DEFAULT_TARGET_LOCALE : $targetLocale;

        return [
            'translation_groups' => $groups->count(),
            'stale_groups' => $groups->filter(fn (array $group): bool => (int) $group['stale_locales_count'] > 0)->count(),
            'published_groups' => $groups->filter(fn (array $group): bool => ! empty($group['published_locales']))->count(),
            'missing_target_locale' => $groups->filter(fn (array $group): bool => ! in_array($targetLocale, $group['locale_codes'], true))->count(),
            'ownership_mismatch_groups' => $groups->filter(fn (array $group): bool => ! (bool) $group['ownership_ok'])->count(),
            'canonical_risk_groups' => $groups->filter(fn (array $group): bool => ! (bool) $group['canonical_ok'])->count(),
        ];
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @return array<string, list<string>>
     */
    private function filterOptions(Collection $articles): array
    {
        $locales = $articles
            ->pluck('locale')
            ->merge(['zh-CN'])
            ->merge($this->targetLocales())
            ->filter()
            ->map(fn (mixed $locale): string => (string) $locale)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'locales' => $locales,
            'statuses' => Article::translationStatuses(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function selectedGroup(Collection $groups, ?string $selectedGroupId): ?array
    {
        if (! filled($selectedGroupId)) {
            return $groups->first();
        }

        return $groups->first(
            fn (array $group): bool => (string) $group['translation_group_id'] === (string) $selectedGroupId
        );
    }

    private function isSource(Article $article): bool
    {
        return $article->isSourceArticle()
            || $article->translation_status === Article::TRANSLATION_STATUS_SOURCE
            || ((string) $article->locale === (string) $article->source_locale && $article->source_article_id === null);
    }

    /**
     * @param  Collection<int, Article>  $sourceArticles
     * @param  Collection<int, Article>  $articles
     */
    private function selectSourceArticle(Collection $sourceArticles, Collection $articles): ?Article
    {
        return ($sourceArticles->isNotEmpty() ? $sourceArticles : $articles)
            ->sortBy(fn (Article $article): string => implode('-', [
                (int) $article->org_id === self::PUBLIC_ARTICLE_ORG_ID ? '0' : '1',
                (string) $article->status === 'published' && (bool) $article->is_public ? '0' : '1',
                $article->source_article_id === null ? '0' : '1',
                str_pad((string) $article->id, 12, '0', STR_PAD_LEFT),
            ]))
            ->first();
    }

    /**
     * @param  Collection<int, Article>  $articles
     */
    private function belongsToPublicArticleSurface(Collection $articles): bool
    {
        $publicArticleIds = $articles
            ->filter(fn (Article $article): bool => (int) $article->org_id === self::PUBLIC_ARTICLE_ORG_ID)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (! empty($publicArticleIds)) {
            return true;
        }

        return $articles->contains(
            fn (Article $article): bool => in_array((int) ($article->source_article_id ?? 0), $publicArticleIds, true)
        );
    }

    private function sourceHash(?Article $source): ?string
    {
        if (! $source instanceof Article) {
            return null;
        }

        if ($source->workingRevision instanceof ArticleTranslationRevision && filled($source->workingRevision->source_version_hash)) {
            return (string) $source->workingRevision->source_version_hash;
        }

        return filled($source->source_version_hash) ? (string) $source->source_version_hash : null;
    }

    private function shortHash(mixed $hash): ?string
    {
        if (! filled($hash)) {
            return null;
        }

        return Str::limit((string) $hash, 12, '');
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function localizedPreflight(array $preflight): array
    {
        $preflight['blockers'] = array_values(array_map(
            fn (string $blocker): string => $this->localizedBlocker($blocker),
            (array) ($preflight['blockers'] ?? [])
        ));

        return $preflight;
    }

    private function localizedBlocker(string $blocker): string
    {
        $key = str_replace(['/', ' '], ['_', '_'], strtolower(trim($blocker)));
        $translationKey = "ops.translation_ops.blockers.{$key}";

        return Lang::has($translationKey) ? (string) __($translationKey) : $blocker;
    }

    private function localizedReason(mixed $reason): ?string
    {
        $raw = trim((string) $reason);
        if ($raw === '') {
            return null;
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
        ];
    }

    /**
     * @return list<string>
     */
    private function targetLocales(): array
    {
        $configured = config('services.article_translation.target_locales', [self::DEFAULT_TARGET_LOCALE]);
        $locales = is_array($configured) ? $configured : [self::DEFAULT_TARGET_LOCALE];
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $locale): string => trim((string) $locale),
            $locales
        ))));

        return $normalized === [] ? [self::DEFAULT_TARGET_LOCALE] : $normalized;
    }
}
