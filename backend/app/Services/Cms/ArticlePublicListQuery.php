<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTestEdge;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentMaterialDecision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class ArticlePublicListQuery
{
    /** Fresh, bounded feed reads never use the list's stale/LKG payload. */
    public function feed(int $orgId, string $locale): \Illuminate\Database\Eloquent\Collection
    {
        $changedAt = ContentMaterialDecision::query()
            ->select('material_changed_at')
            ->whereColumn('org_id', 'articles.org_id')
            ->whereColumn('locale', 'articles.locale')
            ->where('family', 'article')
            ->where('authority_revision_kind', 'article_translation_revision')
            ->whereColumn('authority_revision', 'articles.published_revision_id')
            ->where('publication_state', 'published')
            ->orderByDesc('id')->limit(1);
        $articles = $this->publicQuery(['org_id' => $orgId])
            ->where('articles.locale', $locale)
            ->whereNotNull('articles.published_at')
            ->selectSub($changedAt, 'feed_material_changed_at')
            ->orderByRaw('COALESCE(feed_material_changed_at, list_revision.published_at, articles.published_at) DESC')
            ->orderByDesc('articles.id')->limit(100)->get();
        $this->hydrate($articles);

        return $articles;
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     * @return LengthAwarePaginator<int, Article>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->publicQuery($filters);

        $this->applyFilters($query, $filters);
        $this->applyOrdering($query, $filters);

        $paginator = $query->paginate(
            $filters['per_page'],
            ['*'],
            'page',
            $filters['page'],
        );

        $this->hydrate($paginator->getCollection());

        return $paginator;
    }

    private function publicQuery(array $filters): Builder
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->join('article_translation_revisions as list_revision', function (JoinClause $join): void {
                $join
                    ->on('list_revision.id', '=', 'articles.published_revision_id')
                    ->on('list_revision.article_id', '=', 'articles.id')
                    ->on('list_revision.org_id', '=', 'articles.org_id')
                    ->on('list_revision.locale', '=', 'articles.locale')
                    ->where('list_revision.revision_status', ArticleTranslationRevision::STATUS_PUBLISHED)
                    ->where(static function (JoinClause $publishedAt): void {
                        $publishedAt
                            ->whereNull('list_revision.published_at')
                            ->orWhere('list_revision.published_at', '<=', now());
                    });
            })
            ->leftJoin('article_categories as list_category', function (JoinClause $join): void {
                $join
                    ->on('list_category.id', '=', 'articles.category_id')
                    ->on('list_category.org_id', '=', 'articles.org_id');
            })
            ->where('articles.org_id', $filters['org_id'])
            ->whereNull('articles.deleted_at')
            ->published()
            ->where(fn (Builder $q) => $q->whereNull('articles.published_at')->orWhere('articles.published_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('articles.scheduled_at')->orWhere('articles.scheduled_at', '<=', now()))
            ->select(array_merge(
                ['articles.*'],
                $this->revisionSelects(),
                $this->categorySelects(),
            ));

    }

    /** Selected records share the exact list eligibility and retain CMS order. */
    public function selected(int $orgId, string $locale, array $ids): \Illuminate\Database\Eloquent\Collection
    {
        $articles = $this->publicQuery(['org_id' => $orgId])->where('articles.locale', $locale)
            ->whereIn('articles.id', $ids)->get();
        $this->hydrate($articles);
        $rank = array_flip($ids);

        return $articles->sortBy(fn (Article $article) => $rank[$article->id])->values();
    }

    /** Counts are scoped to a language and qualifying published revisions. */
    public function categoryCounts(int $orgId, string $locale): array
    {
        return $this->publicQuery(['org_id' => $orgId])->where('articles.locale', $locale)
            ->where('list_category.is_active', true)->reorder()
            ->select('list_category.slug')->selectRaw('count(*) as article_count')
            ->groupBy('list_category.slug')->pluck('article_count', 'list_category.slug')->all();
    }

    private function hydrate(\Illuminate\Database\Eloquent\Collection $articles): void
    {
        foreach ($articles as $article) {
            $this->hydrateJoinedRelations($article);
        }

        $articles->load([
            'tags' => static fn ($tagQuery) => $tagQuery->withoutGlobalScopes(),
            'testEdges' => static fn ($edgeQuery) => $edgeQuery
                ->withoutGlobalScopes()
                ->where('visibility', ArticleTestEdge::VISIBILITY_PUBLIC)
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);

    }

    /**
     * @param  Builder<Article>  $query
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['category'] ?? null) !== null) {
            $query->where('list_category.slug', $filters['category'])->where('list_category.is_active', true);
        }

        if ($filters['locale'] !== null) {
            $query->where('articles.locale', $filters['locale']);
        }

        if ($filters['related_test_slug'] !== null) {
            $relatedTestSlug = $filters['related_test_slug'];
            $query->where(static function (Builder $relatedQuery) use ($relatedTestSlug, $filters): void {
                $relatedQuery
                    ->where('articles.related_test_slug', $relatedTestSlug)
                    ->orWhereExists(static function ($edgeQuery) use ($relatedTestSlug, $filters): void {
                        $edgeQuery
                            ->selectRaw('1')
                            ->from('article_test_edges')
                            ->whereColumn('article_test_edges.article_id', 'articles.id')
                            ->whereColumn('article_test_edges.org_id', 'articles.org_id')
                            ->where('article_test_edges.test_slug', $relatedTestSlug)
                            ->where('article_test_edges.visibility', ArticleTestEdge::VISIBILITY_PUBLIC);

                        if ($filters['locale'] !== null) {
                            $edgeQuery->where('article_test_edges.locale', $filters['locale']);
                        }
                    });
            });
        }

        if ($filters['voice'] !== null) {
            $query->where('articles.voice', $filters['voice']);
        }
    }

    /**
     * @param  Builder<Article>  $query
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     */
    private function applyOrdering(Builder $query, array $filters): void
    {
        if ($filters['related_test_slug'] !== null) {
            $query
                ->orderByRaw(
                    'CASE WHEN articles.related_test_slug = ? THEN 0 ELSE 1 END',
                    [$filters['related_test_slug']],
                )
                ->orderByRaw(
                    '(select min(sort_order) from article_test_edges where article_test_edges.article_id = articles.id and article_test_edges.test_slug = ? and article_test_edges.visibility = ?) asc',
                    [$filters['related_test_slug'], ArticleTestEdge::VISIBILITY_PUBLIC],
                );
        }

        $query
            ->orderByDesc('articles.published_at')
            ->orderByRaw('articles.voice_order is null')
            ->orderBy('articles.voice_order')
            ->orderByDesc('articles.id');
    }

    /**
     * @return list<string>
     */
    private function revisionSelects(): array
    {
        return [
            'list_revision.id as list_revision_id',
            'list_revision.org_id as list_revision_org_id',
            'list_revision.article_id as list_revision_article_id',
            'list_revision.locale as list_revision_locale',
            'list_revision.revision_status as list_revision_status',
            'list_revision.title as list_revision_title',
            'list_revision.excerpt as list_revision_excerpt',
            'list_revision.content_md as list_revision_content_md',
            'list_revision.reviewed_at as list_revision_reviewed_at',
            'list_revision.approved_at as list_revision_approved_at',
            'list_revision.published_at as list_revision_published_at',
            'list_revision.created_at as list_revision_created_at',
            'list_revision.updated_at as list_revision_updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function categorySelects(): array
    {
        return [
            'list_category.id as list_category_id',
            'list_category.org_id as list_category_org_id',
            'list_category.slug as list_category_slug',
            'list_category.name as list_category_name',
            'list_category.description as list_category_description',
            'list_category.sort_order as list_category_sort_order',
            'list_category.is_active as list_category_is_active',
            'list_category.created_at as list_category_created_at',
            'list_category.updated_at as list_category_updated_at',
        ];
    }

    private function hydrateJoinedRelations(Article $article): void
    {
        $revision = new ArticleTranslationRevision;
        $revision->setConnection($article->getConnectionName());
        $revision->setRawAttributes([
            'id' => $article->getAttribute('list_revision_id'),
            'org_id' => $article->getAttribute('list_revision_org_id'),
            'article_id' => $article->getAttribute('list_revision_article_id'),
            'locale' => $article->getAttribute('list_revision_locale'),
            'revision_status' => $article->getAttribute('list_revision_status'),
            'title' => $article->getAttribute('list_revision_title'),
            'excerpt' => $article->getAttribute('list_revision_excerpt'),
            'content_md' => $article->getAttribute('list_revision_content_md'),
            'reviewed_at' => $article->getAttribute('list_revision_reviewed_at'),
            'approved_at' => $article->getAttribute('list_revision_approved_at'),
            'published_at' => $article->getAttribute('list_revision_published_at'),
            'created_at' => $article->getAttribute('list_revision_created_at'),
            'updated_at' => $article->getAttribute('list_revision_updated_at'),
        ], true);
        $revision->exists = true;
        $article->setRelation('publishedRevision', $revision);

        $categoryId = $article->getAttribute('list_category_id');
        if ($categoryId === null) {
            $article->setRelation('category', null);
        } else {
            $category = new ArticleCategory;
            $category->setConnection($article->getConnectionName());
            $category->setRawAttributes([
                'id' => $categoryId,
                'org_id' => $article->getAttribute('list_category_org_id'),
                'slug' => $article->getAttribute('list_category_slug'),
                'name' => $article->getAttribute('list_category_name'),
                'description' => $article->getAttribute('list_category_description'),
                'sort_order' => $article->getAttribute('list_category_sort_order'),
                'is_active' => $article->getAttribute('list_category_is_active'),
                'created_at' => $article->getAttribute('list_category_created_at'),
                'updated_at' => $article->getAttribute('list_category_updated_at'),
            ], true);
            $category->exists = true;
            $article->setRelation('category', $category);
        }

        foreach (array_merge($this->revisionAliasNames(), $this->categoryAliasNames()) as $attribute) {
            $article->offsetUnset($attribute);
        }
    }

    /**
     * @return list<string>
     */
    private function revisionAliasNames(): array
    {
        return [
            'list_revision_id',
            'list_revision_org_id',
            'list_revision_article_id',
            'list_revision_locale',
            'list_revision_status',
            'list_revision_title',
            'list_revision_excerpt',
            'list_revision_content_md',
            'list_revision_reviewed_at',
            'list_revision_approved_at',
            'list_revision_published_at',
            'list_revision_created_at',
            'list_revision_updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function categoryAliasNames(): array
    {
        return [
            'list_category_id',
            'list_category_org_id',
            'list_category_slug',
            'list_category_name',
            'list_category_description',
            'list_category_sort_order',
            'list_category_is_active',
            'list_category_created_at',
            'list_category_updated_at',
        ];
    }
}
