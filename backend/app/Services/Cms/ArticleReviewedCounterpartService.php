<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Support\CanonicalFrontendUrl;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Support\Arr;
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

    public function eligible(Article $article): bool
    {
        $article->loadMissing(['publishedRevision', 'seoMeta']);
        $seo = $article->seoMeta;
        $robots = array_map('trim', explode(',', strtolower((string) $seo?->robots)));

        return (int) $article->org_id === 0 && in_array($article->locale, ['en', 'zh-CN'], true)
            && $article->deleted_at === null && $article->status === 'published' && $article->is_public && $article->is_indexable
            && ! in_array($article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)
            && $article->published_at !== null && ! $article->published_at->isFuture()
            && $article->publishedRevision !== null && $article->publishedRevision->isPubliclyReadableForArticle($article)
            && $seo !== null && (int) $seo->org_id === 0 && $seo->locale === $article->locale && $seo->is_indexable
            && ! array_intersect(['noindex', 'none'], $robots)
            && in_array($seo->canonical_url, [$this->canonical($article), parse_url($this->canonical($article), PHP_URL_PATH)], true)
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', (string) $article->slug) === 1;
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
        return CanonicalFrontendUrl::APEX_URL.'/'.($article->locale === 'zh-CN' ? 'zh' : 'en').'/articles/'.$article->slug;
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
