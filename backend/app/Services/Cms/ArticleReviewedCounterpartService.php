<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalFrontendUrl;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Published-language correspondence is independent of historical translation ownership. */
final class ArticleReviewedCounterpartService
{
    public function binding(Article $article): ?array
    {
        $article->loadMissing('seoMeta');
        $gate = data_get($article->seoMeta?->schema_json, 'editorial_package_v1.hreflang_gate_v1', []);

        return is_array($gate) && array_key_exists('counterpart_binding_v1', $gate)
            ? (is_array($gate['counterpart_binding_v1']) ? $gate['counterpart_binding_v1'] : [])
            : null;
    }

    /** null means the legacy group reader applies; [] means a stale or invalid explicit pair. */
    public function resolve(Article $article): ?array
    {
        $binding = $this->binding($article);
        if ($binding === null) {
            return null;
        }
        try {
            return DB::transaction(function () use ($article, $binding): array {
                $endpoints = $binding['endpoints'] ?? null;
                if (! is_array($endpoints) || ! array_is_list($endpoints) || count($endpoints) !== 2) {
                    return [];
                }
                $ids = array_column($endpoints, 'article_id');
                if (count($ids) !== 2 || count(array_unique($ids)) !== 2
                    || ! in_array((int) $article->id, $ids, true)
                    || count(array_filter($ids, fn ($id): bool => is_int($id) && $id > 0)) !== 2) {
                    return [];
                }
                $members = Article::withoutGlobalScopes()->where('org_id', 0)->whereIn('id', $ids)
                    ->with(['publishedRevision', 'seoMeta'])->orderBy('id')->get()->all();
                if (count($members) !== 2 || ! $this->eligible($members[0]) || ! $this->eligible($members[1])
                    || $members[0]->locale === $members[1]->locale
                    || ! Hash::sameValue($binding, $this->createBinding($members))) {
                    return [];
                }
                foreach ($members as $member) {
                    $gate = data_get($member->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1', []);
                    if (($gate['enabled'] ?? null) !== true || ($gate['policy'] ?? null) !== 'reciprocal_counterparts_verified'
                        || ! Hash::sameValue($this->binding($member), $binding)
                        || data_get($member->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold')) {
                        return [];
                    }
                }

                return $members;
            });
        } catch (Throwable) {
            return [];
        }
    }

    /** null leaves the legacy group in charge. A stale binding cannot be replaced by a group match. */
    public function resolveForSearch(Article $article): ?array
    {
        $pair = $this->resolve($article);
        if ($pair === null && $article->locale === 'zh-TW' && $article->source_locale === 'zh-CN') {
            $source = Article::withoutGlobalScopes()->where('org_id', 0)
                ->whereKey($article->source_article_id)->where('locale', 'zh-CN')
                ->with('seoMeta')->first();
            $pair = $source instanceof Article ? $this->resolve($source) : null;
        }
        if ($pair === null || $pair === []) {
            return $pair;
        }

        $source = collect($pair)->firstWhere('locale', 'zh-CN');
        $traditional = $this->traditionalSearchCounterpart($source);
        if ($article->locale === 'zh-TW' && (int) $traditional?->id !== (int) $article->id) {
            return [];
        }

        return $traditional instanceof Article ? [...$pair, $traditional] : $pair;
    }

    private function traditionalSearchCounterpart(Article $source): ?Article
    {
        $group = trim((string) $source->translation_group_id);
        if ($group === '') {
            return null;
        }
        $targets = Article::withoutGlobalScopes()->where('org_id', 0)
            ->where('source_article_id', $source->id)->where('source_locale', 'zh-CN')
            ->where('translation_group_id', $group)->where('locale', 'zh-TW')->publiclyReadable()
            ->with(['publishedRevision', 'seoMeta'])->limit(2)->get();
        $target = $targets->count() === 1 ? $targets->sole() : null;
        if (! $target instanceof Article || ! $this->eligible($target, includeTraditional: true)
            || (int) $target->publishedRevision->source_article_id !== (int) $source->id
            || $target->publishedRevision->source_locale !== 'zh-CN'
            || $target->publishedRevision->translation_group_id !== $group
            || $this->binding($target) !== null
            || data_get($target->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled') !== true
            || data_get($target->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold')) {
            return null;
        }

        return $target;
    }

    public function eligible(Article $article, bool $includeTraditional = false): bool
    {
        $article->loadMissing(['publishedRevision', 'seoMeta']);
        $seo = $article->seoMeta;
        $robots = array_map('trim', explode(',', strtolower((string) $seo?->robots)));

        return (int) $article->org_id === 0 && in_array($article->locale, $includeTraditional ? ArticleSourceTargetSnapshot::TRANSLATION_TARGET_LOCALES : ['en', 'zh-CN'], true)
            && $article->deleted_at === null && $article->status === 'published' && $article->is_public && $article->is_indexable
            && ! in_array($article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)
            && $article->published_at !== null && ! $article->published_at->isFuture()
            && $article->publishedRevision !== null && $article->publishedRevision->isPubliclyReadableForArticle($article)
            && $seo !== null && (int) $seo->org_id === 0 && $seo->locale === $article->locale && $seo->is_indexable
            && ! array_intersect(['noindex', 'none'], $robots)
            && in_array($seo->canonical_url, [$this->canonical($article), parse_url($this->canonical($article), PHP_URL_PATH)], true)
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', (string) $article->slug) === 1;
    }

    /** @return array<string, string> Published reader destinations, independent of search eligibility. */
    public function readerLanguageSlugs(Article $article): array
    {
        if ((int) $article->org_id !== 0 || ! in_array($article->locale, ArticleSourceTargetSnapshot::TRANSLATION_TARGET_LOCALES, true)) {
            return [];
        }

        $reviewed = $article->locale === 'en' ? $this->resolve($article) : null;
        $englishRoot = $article->locale === 'en' && ! $article->source_article_id && $reviewed === null;
        $legacyChinese = $englishRoot ? $this->legacyGroupRoots($article, 'zh-CN') : collect();
        if ($legacyChinese->count() > 1) {
            return [];
        }
        $sourceLocale = match (true) {
            $article->locale !== 'en' && $article->source_locale === 'en',
            $englishRoot && $legacyChinese->isEmpty() => 'en',
            default => 'zh-CN',
        };
        $sourceId = $article->locale === $sourceLocale ? (int) $article->id : (int) $article->source_article_id;
        if ($legacyChinese->isNotEmpty()) {
            $sourceId = (int) $legacyChinese->sole()->id;
        }
        if ($reviewed !== null) {
            $sourceId = (int) collect($reviewed)->firstWhere('locale', 'zh-CN')?->id;
        }
        if ($sourceId < 1) {
            return [];
        }

        $members = Article::withoutGlobalScopes()->where('org_id', 0)->publiclyReadable()
            ->where(static function ($query) use ($sourceId): void {
                $query->whereKey($sourceId)->orWhere('source_article_id', $sourceId);
            })
            ->whereIn('locale', ArticleSourceTargetSnapshot::TRANSLATION_TARGET_LOCALES)
            ->with(['publishedRevision', 'seoMeta'])->get();
        $source = $members->firstWhere('id', $sourceId);
        if ($source?->locale !== $sourceLocale || ! $this->readableForNavigation($source)
            || ($sourceLocale === 'en' && ($this->binding($source) !== null
                || ($article->locale !== 'en' && $this->legacyGroupRoots($source, 'zh-CN')->isNotEmpty())))) {
            return [];
        }

        $group = trim((string) $source->translation_group_id);
        if ($group === '') {
            return [];
        }
        $siblings = $members->filter(fn (Article $member): bool => $member->source_locale === $sourceLocale
            && (int) $member->source_article_id === $sourceId && $member->translation_group_id === $group
            && (int) $member->publishedRevision?->source_article_id === $sourceId && $this->readableForNavigation($member));
        $traditional = $siblings->where('locale', 'zh-TW');
        if ($traditional->count() !== 1) {
            return [];
        }

        $destinations = collect([$source, $traditional->sole()]);
        if ($sourceLocale === 'en') {
            $chinese = $siblings->where('locale', 'zh-CN');
            if ($chinese->count() === 1 && $this->binding($chinese->sole()) === null) {
                $destinations->push($chinese->sole());
            }
        } else {
            $reviewed ??= $this->resolve($source);
            $english = $reviewed === null
                ? $siblings->where('locale', 'en')
                : collect($reviewed)->where('locale', 'en')->filter(fn (Article $member): bool => $this->readableForNavigation($member));
            if ($reviewed === null && $english->isEmpty()) {
                $legacy = $this->legacyGroupRoots($source, 'en');
                if ($legacy->count() === 1 && $this->binding($legacy->sole()) === null) {
                    $roots = $this->legacyGroupRoots($source, 'zh-CN');
                    if ($roots->count() === 1 && $roots->sole()->id === $source->id) {
                        $english = $legacy;
                    }
                }
            }
            if ($english->count() === 1) {
                $destinations->push($english->sole());
            }
        }
        if (! $destinations->contains(fn (Article $member): bool => (int) $member->id === (int) $article->id)) {
            return [];
        }

        return $destinations->mapWithKeys(static fn (Article $member): array => [$member->locale => $member->slug])->all();
    }

    /** @return Collection<int, Article> */
    private function legacyGroupRoots(Article $article, string $locale): Collection
    {
        $group = trim((string) $article->translation_group_id);
        if ($group === '') {
            return collect();
        }

        // Some original variants are independent roots in an explicit translation group.
        return Article::withoutGlobalScopes()->where('org_id', 0)->where('locale', $locale)
            ->where('source_locale', $locale)->where('translation_group_id', $group)
            ->whereNull('source_article_id')->whereNull('translated_from_article_id')
            ->whereNull('deleted_at')->publiclyReadable()->with(['publishedRevision', 'seoMeta'])
            ->get()->filter(fn (Article $member): bool => $this->readableForNavigation($member));
    }

    private function readableForNavigation(Article $article): bool
    {
        $revision = $article->publishedRevision;

        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', (string) $article->slug) === 1
            && $revision !== null && $revision->isPubliclyReadableForArticle($article)
            && trim((string) $revision->content_md) !== '';
    }

    /** Published text and public reader semantics; private working drafts do not change this proof. */
    public function createBinding(array $members): array
    {
        usort($members, fn (Article $a, Article $b): int => $a->id <=> $b->id);

        return ['endpoints' => array_map(fn (Article $a): array => [
            'article_id' => (int) $a->id,
            'locale' => (string) $a->locale,
            'slug' => (string) $a->slug,
            'canonical_url' => $this->canonical($a),
            'published_revision_id' => (int) $a->published_revision_id,
            'published_revision_sha256' => Hash::hash($a->publishedRevision->getAttributes()),
            'public_reader_sha256' => $this->publicReaderHash($a),
        ], $members)];
    }

    private function publicReaderHash(Article $article): string
    {
        $keys = ['answer_surface_policy', 'answer_surface_visibility', 'answer_surface_v1'];

        // Bind both authoritative FAQ inputs, including the ordered SEO override.
        // Omit workflow evidence and hreflang gates to avoid a self-referential proof.
        return Hash::hash([
            'article' => $article->only(['title', 'excerpt', 'author_name', 'cover_image_url',
                'cover_image_alt', 'related_test_slug', 'reading_minutes']),
            'variant_answers' => Arr::only((array) data_get($article->cover_image_variants, 'editorial_package_v1', []), $keys),
            'seo_answers' => Arr::only((array) data_get($article->seoMeta?->schema_json, 'editorial_package_v1', []), $keys),
        ]);
    }

    public function canonical(Article $article): string
    {
        $segment = match ($article->locale) {
            'zh-CN' => 'zh',
            'zh-TW' => 'zh-TW',
            default => 'en',
        };

        return CanonicalFrontendUrl::APEX_URL.'/'.$segment.'/articles/'.$article->slug;
    }

    /** Keep raw, even expired endpoints for bounded removal of both sides' cached tags. */
    public function cachePeers(Article $article): array
    {
        $endpoints = $this->binding($article)['endpoints'] ?? [];
        if (! is_array($endpoints) || count($endpoints) !== 2
            || ! in_array((int) $article->id, array_column($endpoints, 'article_id'), true)) {
            return [];
        }
        $records = [];
        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint) || ! is_int($endpoint['article_id'] ?? null)
                || ! in_array($endpoint['locale'] ?? null, ['en', 'zh-CN'], true)
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', (string) ($endpoint['slug'] ?? '')) !== 1) {
                continue;
            }
            $record = Article::withoutGlobalScopes()->where('org_id', 0)->find($endpoint['article_id']);
            if (! $record) {
                // A removed peer still needs its former locale/slug tags cleared.
                $record = new Article;
                $record->setRawAttributes(['id' => $endpoint['article_id'], 'org_id' => 0]);
            } else {
                $records[] = $record;
            }
            $previous = clone $record;
            $previous->setAttribute('locale', $endpoint['locale']);
            $previous->setAttribute('slug', $endpoint['slug']);
            $records[] = $previous;
        }

        return collect($records)->unique(fn (Article $a): string => $a->id.':'.$a->locale.':'.$a->slug)
            ->reject(fn (Article $a): bool => $a->id === $article->id && $a->locale === $article->locale && $a->slug === $article->slug)
            ->values()->all();
    }
}
