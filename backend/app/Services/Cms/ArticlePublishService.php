<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ArticlePublishService
{
    private const SEO13_ATOMIC_PROMOTION_SOURCE = 'seo13_atomic_existing_article_working_revision_promotion';

    public function __construct(
        private readonly SeoDiscoverabilityCacheInvalidator $seoDiscoverabilityCacheInvalidator,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
    ) {}

    public function publishArticle(int $articleId, string $source = 'article_publish_service'): Article
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        $article = DB::transaction(function () use ($articleId): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $this->assertPublishable($article);
            $publishedRevision = $this->resolvePublishableRevision($article);

            $article->status = 'published';
            $article->is_public = true;
            $article->published_at = now();
            $article->published_revision_id = $publishedRevision->id;
            $article->save();

            $publishedRevision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'published_at' => $publishedRevision->published_at ?? $article->published_at,
            ])->save();

            return $article->fresh(['publishedRevision']) ?? $article;
        });

        ContentReleaseAudit::log('article', $article, $source);
        $this->seoDiscoverabilityCacheInvalidator->flushArticleDiscoverabilityCaches();

        return $article;
    }

    public function unpublishArticle(int $articleId): Article
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        $article = DB::transaction(function () use ($articleId): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $article->status = 'draft';
            $article->is_public = false;
            $article->save();

            return $article->fresh() ?? $article;
        });

        $this->seoDiscoverabilityCacheInvalidator->flushArticleDiscoverabilityCaches(false);

        return $article;
    }

    public function promoteExistingWorkingRevision(
        int $articleId,
        int $workingRevisionId,
        int $currentPublishedRevisionId,
        string $source = 'existing_article_controlled_promotion',
        bool $dispatchFollowUp = true,
        ?Closure $transactionGuard = null,
        bool $recordReleaseAudit = true,
        bool $invalidateDiscoverabilityCaches = true,
    ): Article {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        if ($workingRevisionId <= 0) {
            throw new InvalidArgumentException('working_revision_id must be positive.');
        }

        if ($currentPublishedRevisionId <= 0) {
            throw new InvalidArgumentException('current_published_revision_id must be positive.');
        }

        $suppressesDefaultSideEffects = ! $recordReleaseAudit || ! $invalidateDiscoverabilityCaches;
        if ($suppressesDefaultSideEffects
            && $source !== 'seo13_atomic_existing_article_working_revision_promotion') {
            throw new InvalidArgumentException('promotion side effects may be held only by the locked SEO 13 atomic lane.');
        }
        if (! $recordReleaseAudit && $dispatchFollowUp) {
            throw new InvalidArgumentException('follow-up dispatch cannot run when release audit is held.');
        }

        $article = DB::transaction(function () use ($articleId, $workingRevisionId, $currentPublishedRevisionId, $transactionGuard): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
                throw new InvalidArgumentException('existing article promotion requires an already-published public article.');
            }

            if ((int) ($article->published_revision_id ?? 0) !== $currentPublishedRevisionId) {
                throw new InvalidArgumentException('current published revision lock no longer matches.');
            }

            if ((int) ($article->working_revision_id ?? 0) !== $workingRevisionId) {
                throw new InvalidArgumentException('working revision lock no longer matches.');
            }

            if ($workingRevisionId === $currentPublishedRevisionId) {
                throw new InvalidArgumentException('working revision must be isolated from the current published revision.');
            }

            $previousPublishedRevision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->where('id', $currentPublishedRevisionId)
                ->where('article_id', $articleId)
                ->lockForUpdate()
                ->first();
            if (! $previousPublishedRevision instanceof ArticleTranslationRevision) {
                throw new RuntimeException('current published revision not found.');
            }

            $this->assertPublishableArticleProjection($article);

            $workingRevision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->where('id', $workingRevisionId)
                ->where('article_id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $workingRevision instanceof ArticleTranslationRevision) {
                throw new RuntimeException('working revision not found.');
            }

            if ((string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                throw new InvalidArgumentException('working revision must be approved before promotion.');
            }

            if (! $workingRevision->isPublishableForArticle($article)) {
                throw new InvalidArgumentException('working revision is not publishable for this article.');
            }

            if ((int) $workingRevision->article_id !== (int) $article->id
                || (int) $workingRevision->org_id !== (int) $article->org_id
                || (string) $workingRevision->locale !== (string) $article->locale) {
                throw new InvalidArgumentException('working revision does not match article identity.');
            }

            if ((string) $workingRevision->translation_group_id !== (string) $article->translation_group_id) {
                throw new InvalidArgumentException('working revision translation group does not match article.');
            }

            if (trim((string) $workingRevision->title) === '') {
                throw new InvalidArgumentException('revision title must exist before promotion.');
            }

            if (trim((string) $workingRevision->content_md) === '') {
                throw new InvalidArgumentException('revision content_md must exist before promotion.');
            }

            if ($transactionGuard instanceof Closure) {
                $transactionGuard($article, $workingRevision);
            }

            $this->articleBodyHeadingGuard->assertNoBodyH1((string) $workingRevision->content_md);

            $publishedAt = now();

            $article->forceFill([
                'title' => (string) $workingRevision->title,
                'excerpt' => $workingRevision->excerpt,
                'content_md' => (string) $workingRevision->content_md,
                'content_html' => null,
                'status' => 'published',
                'is_public' => true,
                'published_at' => $publishedAt,
                'published_revision_id' => $workingRevisionId,
            ])->save();

            $workingRevision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'published_at' => $publishedAt,
            ])->save();

            $previousPublishedRevision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_STALE,
            ])->saveQuietly();

            $seoUpdates = [];
            if (filled($workingRevision->seo_title)) {
                $seoUpdates['seo_title'] = (string) $workingRevision->seo_title;
                $seoUpdates['og_title'] = (string) $workingRevision->seo_title;
            }
            if (filled($workingRevision->seo_description)) {
                $seoUpdates['seo_description'] = (string) $workingRevision->seo_description;
                $seoUpdates['og_description'] = (string) $workingRevision->seo_description;
            }

            if ($seoUpdates !== []) {
                ArticleSeoMeta::query()
                    ->withoutGlobalScopes()
                    ->where('article_id', $articleId)
                    ->update($seoUpdates);
            }

            return $article->fresh(['publishedRevision', 'workingRevision', 'seoMeta']) ?? $article;
        });

        if ($recordReleaseAudit) {
            ContentReleaseAudit::log('article', $article, $source, $dispatchFollowUp);
        }

        if ($invalidateDiscoverabilityCaches) {
            $this->seoDiscoverabilityCacheInvalidator->flushArticleDiscoverabilityCaches();
        }

        return $article;
    }

    /**
     * @param  list<array{
     *   article_id:int,
     *   working_revision_id:int,
     *   current_published_revision_id:int
     * }>  $targets
     * @return array<string,mixed>
     */
    public function promoteExistingWorkingRevisionsAtomically(
        array $targets,
        Closure $validateLockedBatch,
        Closure $transactionGuard,
        Closure $validateReadback,
    ): array {
        if (count($targets) !== 13) {
            throw new InvalidArgumentException('SEO 13 atomic promotion requires exactly 13 targets.');
        }

        $articleIds = [];
        $revisionIds = [];
        foreach ($targets as $target) {
            $articleId = (int) ($target['article_id'] ?? 0);
            $workingRevisionId = (int) ($target['working_revision_id'] ?? 0);
            $publishedRevisionId = (int) ($target['current_published_revision_id'] ?? 0);
            if ($articleId <= 0 || $workingRevisionId <= 0 || $publishedRevisionId <= 0) {
                throw new InvalidArgumentException('SEO 13 atomic promotion target identities must be positive.');
            }
            if ($workingRevisionId === $publishedRevisionId) {
                throw new InvalidArgumentException('SEO 13 atomic promotion revisions must be isolated.');
            }

            $articleIds[] = $articleId;
            $revisionIds[] = $workingRevisionId;
            $revisionIds[] = $publishedRevisionId;
        }

        if (count(array_unique($articleIds)) !== 13 || count(array_unique($revisionIds)) !== 26) {
            throw new InvalidArgumentException('SEO 13 atomic promotion target identities must be unique.');
        }

        sort($articleIds, SORT_NUMERIC);
        sort($revisionIds, SORT_NUMERIC);

        return DB::transaction(function () use (
            $targets,
            $articleIds,
            $revisionIds,
            $validateLockedBatch,
            $transactionGuard,
            $validateReadback,
        ): array {
            $lockedArticleCount = Article::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $articleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->count();
            $lockedRevisionCount = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $revisionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->count();
            $lockedSeoCount = ArticleSeoMeta::query()
                ->withoutGlobalScopes()
                ->whereIn('article_id', $articleIds)
                ->orderBy('article_id')
                ->lockForUpdate()
                ->get()
                ->count();
            ArticleEditorialPackageImport::query()
                ->withoutGlobalScopes()
                ->whereIn('article_id', $articleIds)
                ->orderBy('article_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedArticleCount !== 13 || $lockedRevisionCount !== 26 || $lockedSeoCount !== 13) {
                throw new RuntimeException('SEO 13 atomic promotion locked identity set is incomplete.');
            }

            $lockedState = $validateLockedBatch();
            if (! is_array($lockedState)) {
                throw new RuntimeException('SEO 13 atomic promotion locked validation returned invalid state.');
            }

            foreach ($targets as $target) {
                $this->promoteExistingWorkingRevision(
                    (int) $target['article_id'],
                    (int) $target['working_revision_id'],
                    (int) $target['current_published_revision_id'],
                    self::SEO13_ATOMIC_PROMOTION_SOURCE,
                    dispatchFollowUp: false,
                    transactionGuard: $transactionGuard,
                    recordReleaseAudit: false,
                    invalidateDiscoverabilityCaches: false,
                );
            }

            $readback = $validateReadback($lockedState);
            if (! is_array($readback)) {
                throw new RuntimeException('SEO 13 atomic promotion readback returned invalid state.');
            }

            return $readback;
        }, 1);
    }

    private function assertPublishable(Article $article): void
    {
        $this->assertPublishableArticleProjection($article);
        $this->articleBodyHeadingGuard->assertNoBodyH1(
            (string) $article->content_md,
            (string) ($article->content_html ?? '')
        );
    }

    private function assertPublishableArticleProjection(Article $article): void
    {
        if (in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            throw new InvalidArgumentException('archived or soft-deleted articles cannot be published.');
        }

        if (method_exists($article, 'trashed') && $article->trashed()) {
            throw new InvalidArgumentException('soft-deleted articles cannot be published.');
        }

        if (trim((string) $article->slug) === '') {
            throw new InvalidArgumentException('slug must exist before publish.');
        }

        if (trim((string) $article->title) === '') {
            throw new InvalidArgumentException('title must exist before publish.');
        }

        if (trim((string) $article->content_md) === '') {
            throw new InvalidArgumentException('content_md must exist before publish.');
        }
    }

    private function resolvePublishableRevision(Article $article): ArticleTranslationRevision
    {
        $article->loadMissing('workingRevision');

        $revision = $article->workingRevision instanceof ArticleTranslationRevision
            ? $article->workingRevision
            : app(ArticleTranslationRevisionWorkspace::class)->resolveWorkingRevision($article);

        if (
            $revision->revision_status === ArticleTranslationRevision::STATUS_STALE
            || $revision->revision_status === ArticleTranslationRevision::STATUS_ARCHIVED
            || ! $revision->isPublishableForArticle($article)
        ) {
            throw new InvalidArgumentException('working revision is not publishable.');
        }

        if (trim((string) $revision->title) === '') {
            throw new InvalidArgumentException('revision title must exist before publish.');
        }

        if (trim((string) $revision->content_md) === '') {
            throw new InvalidArgumentException('revision content_md must exist before publish.');
        }

        $this->articleBodyHeadingGuard->assertNoBodyH1((string) $revision->content_md);

        return $revision;
    }
}
