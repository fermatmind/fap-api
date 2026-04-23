<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Collection;
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
            'actions' => $this->groupActions($source),
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
            $issues[] = 'article org mismatch';
        }

        $workingRevision = $article->workingRevision;
        if (! $workingRevision instanceof ArticleTranslationRevision) {
            $issues[] = 'missing working revision';
        } else {
            $issues = array_merge($issues, $this->revisionOwnershipIssues($article, $workingRevision, 'working revision'));
        }

        $publishedRevision = $article->publishedRevision;
        if ($article->published_revision_id !== null && ! $publishedRevision instanceof ArticleTranslationRevision) {
            $issues[] = 'missing published revision';
        }
        if ($publishedRevision instanceof ArticleTranslationRevision) {
            $issues = array_merge($issues, $this->revisionOwnershipIssues($article, $publishedRevision, 'published revision'));
        }

        $seoMeta = $article->seoMeta;
        if ($seoMeta instanceof ArticleSeoMeta) {
            if ((int) $seoMeta->org_id !== self::PUBLIC_ARTICLE_ORG_ID
                || (int) $seoMeta->org_id !== (int) $article->org_id) {
                $issues[] = 'seo_meta org mismatch';
            }
            if ((int) $seoMeta->article_id !== (int) $article->id) {
                $issues[] = 'seo_meta article mismatch';
            }
            if ((string) $seoMeta->locale !== (string) $article->locale) {
                $issues[] = 'seo_meta locale mismatch';
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

        if ((int) $revision->org_id !== self::PUBLIC_ARTICLE_ORG_ID) {
            $issues[] = "{$label} org mismatch";
        } elseif ((int) $revision->org_id !== (int) $article->org_id) {
            $issues[] = "{$label} org mismatch";
        }
        if ((int) $revision->article_id !== (int) $article->id) {
            $issues[] = "{$label} article mismatch";
        }
        if ((string) $revision->locale !== (string) $article->locale) {
            $issues[] = "{$label} locale mismatch";
        }
        if ((string) $revision->translation_group_id !== (string) $article->translation_group_id) {
            $issues[] = "{$label} group mismatch";
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
            $issues[] = 'source article count is '.$sourceArticles->count();
        }

        if (! $source instanceof Article) {
            return $issues;
        }

        foreach ($articles as $article) {
            if ((int) $article->id === (int) $source->id) {
                continue;
            }

            if ((int) ($article->source_article_id ?? 0) !== (int) $source->id) {
                $issues[] = 'translation source_article_id mismatch';
            }
            if ((string) $article->source_locale !== (string) $source->locale) {
                $issues[] = 'translation source_locale mismatch';
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
            $alerts[] = ['label' => 'stale published translation', 'state' => 'failed'];
        }
        if ($locales->contains(fn (array $locale): bool => (bool) $locale['is_stale'])) {
            $alerts[] = ['label' => 'source updated after target review', 'state' => 'warning'];
        }
        if ($locales->contains(
            fn (array $locale): bool => (bool) $locale['is_article_published_public'] && empty($locale['published_revision_id'])
        )) {
            $alerts[] = ['label' => 'missing published revision', 'state' => 'failed'];
        }
        if (! in_array(self::DEFAULT_TARGET_LOCALE, $locales->pluck('locale')->all(), true)) {
            $alerts[] = ['label' => 'missing en locale', 'state' => 'warning'];
        }
        if (! empty($ownershipIssues)) {
            $alerts[] = ['label' => 'ownership mismatch', 'state' => 'failed'];
        }
        if (! empty($canonicalIssues)) {
            $alerts[] = ['label' => 'canonical/source split risk', 'state' => 'failed'];
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
     * @return list<array<string, mixed>>
     */
    private function groupActions(?Article $source): array
    {
        return [
            [
                'label' => 'Open source article',
                'enabled' => $source instanceof Article,
                'url' => $source instanceof Article ? ArticleResource::getUrl('edit', ['record' => $source]) : null,
                'reason' => null,
            ],
            [
                'label' => 'Create translation draft',
                'enabled' => false,
                'url' => null,
                'reason' => 'Draft creation remains in the existing article translation workflow.',
            ],
            [
                'label' => 'Re-sync from source',
                'enabled' => false,
                'url' => null,
                'reason' => 'Source re-sync automation is not enabled in v1.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localeActions(Article $article, string $status, bool $isStale, bool $isSource): array
    {
        return [
            [
                'label' => 'Open target article',
                'enabled' => true,
                'url' => ArticleResource::getUrl('edit', ['record' => $article]),
                'reason' => null,
            ],
            [
                'label' => 'Promote to human review',
                'enabled' => ContentAccess::canWrite()
                    && ! $isSource
                    && $status === Article::TRANSLATION_STATUS_MACHINE_DRAFT
                    && ! $isStale,
                'wire_action' => 'promoteToHumanReview',
                'article_id' => (int) $article->id,
                'reason' => $status === Article::TRANSLATION_STATUS_MACHINE_DRAFT
                    ? 'Requires content write permission and a current revision.'
                    : 'Only machine_draft revisions can be promoted here.',
            ],
            [
                'label' => 'Publish current working revision',
                'enabled' => ContentAccess::canRelease()
                    && ! $isSource
                    && ! $isStale
                    && $article->status !== 'published'
                    && $article->workingRevision instanceof ArticleTranslationRevision,
                'wire_action' => 'publishCurrentRevision',
                'article_id' => (int) $article->id,
                'reason' => 'Uses the existing article release gate and approval checks.',
            ],
            [
                'label' => 'Archive stale revision',
                'enabled' => false,
                'wire_action' => null,
                'article_id' => (int) $article->id,
                'reason' => 'Archive automation is intentionally disabled in v1.',
            ],
        ];
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
            ->merge(['zh-CN', self::DEFAULT_TARGET_LOCALE])
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
}
