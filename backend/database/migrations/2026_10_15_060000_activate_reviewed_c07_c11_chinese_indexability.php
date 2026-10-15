<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reuse the existing native content approvals for these two exact published objects.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 */
return new class extends Migration
{
    private const TARGETS = [
        254 => ['slug' => 'eq60-score-and-results-guide', 'revision_id' => 785, 'source_locale' => null],
        265 => ['slug' => 'a-levels-t-levels-apprenticeship-choose-at-16-england', 'revision_id' => 849, 'source_locale' => 'en'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        $hasQualifiedTargets = DB::transaction(function (): bool {
            $reviews = app(CmsEditorialReviewAttestationService::class);
            $targets = [];
            foreach (self::TARGETS as $articleId => $target) {
                $slug = $target['slug'];
                $sourceLocale = $target['source_locale'];
                $rows = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)
                    ->whereKey($articleId)->where('locale', 'zh-CN')->where('slug', $slug)->lockForUpdate()->get();
                if ($rows->isEmpty()) {
                    continue; // Staging/fresh environments must not release a different object sharing this slug.
                }
                if ($rows->count() !== 1) {
                    throw new RuntimeException('Reviewed Chinese article identity is ambiguous: '.$slug);
                }
                $article = $rows->sole();
                $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->published_revision_id)->lockForUpdate()->first();
                $seoRows = ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
                    ->where('article_id', $article->id)->where('locale', 'zh-CN')->lockForUpdate()->get();
                $seo = $seoRows->count() === 1 ? $seoRows->sole() : null;
                $canonical = 'https://fermatmind.com/zh/articles/'.$slug;

                if ($article->status !== 'published' || ! $article->is_public || $article->trashed()
                    || $article->lifecycle_state !== Article::LIFECYCLE_ACTIVE || filled($article->lifecycle_note)
                    || ! $article->published_at || $article->published_at->isFuture()
                    || (int) $article->published_revision_id !== $target['revision_id']
                    || ! $revision || (int) $revision->org_id !== 0 || (int) $revision->article_id !== (int) $article->id
                    || $revision->locale !== 'zh-CN' || $revision->revision_status !== 'published'
                    || ! $revision->approved_at || ! $revision->published_at || $revision->published_at->isFuture()
                    || trim((string) $revision->content_md) === ''
                    || ! hash_equals(hash('sha256', (string) $revision->content_md), hash('sha256', (string) $article->content_md))
                    || ! $seo || $seo->canonical_url !== $canonical
                    || ! $reviews->hasApprovedEvidence('article', $article)
                    || ! $reviews->hasApprovedEvidence('article_translation_revision', $revision)) {
                    throw new RuntimeException('Reviewed Chinese article is not a current approved public revision: '.$slug);
                }
                if ($sourceLocale === null) {
                    if ($article->source_article_id !== null || $article->source_locale !== 'zh-CN') {
                        throw new RuntimeException('Reviewed Chinese article source identity changed: '.$slug);
                    }
                } else {
                    $source = Article::withoutGlobalScopes()->withTrashed()->whereKey($article->source_article_id)->lockForUpdate()->first();
                    $sourceRevision = $source ? ArticleTranslationRevision::withoutGlobalScopes()->whereKey($source->published_revision_id)->lockForUpdate()->first() : null;
                    if (! $source || (int) $source->org_id !== 0 || $source->locale !== $sourceLocale || $source->slug !== $slug
                        || $source->status !== 'published' || ! $source->is_public || $source->trashed()
                        || $source->lifecycle_state !== Article::LIFECYCLE_ACTIVE
                        || ! $source->published_at || $source->published_at->isFuture()
                        || ! $sourceRevision || (int) $sourceRevision->org_id !== 0 || (int) $sourceRevision->article_id !== (int) $source->id
                        || $sourceRevision->locale !== $sourceLocale || $sourceRevision->revision_status !== 'published'
                        || ! $sourceRevision->approved_at || ! $sourceRevision->published_at || $sourceRevision->published_at->isFuture()
                        || ! $source->translation_group_id || $source->translation_group_id !== $article->translation_group_id
                        || ! $source->source_version_hash || $source->source_version_hash !== $revision->translated_from_version_hash
                        || $source->source_version_hash !== $article->translated_from_version_hash
                        || ! $reviews->hasApprovedEvidence('article_translation_revision', $sourceRevision)) {
                        throw new RuntimeException('Reviewed Chinese article translation source is stale: '.$slug);
                    }
                }
                $targets[] = [$article, $seo];
            }

            foreach ($targets as [$article, $seo]) {
                if ($article->is_indexable && $article->sitemap_eligible && $article->llms_eligible
                    && $seo->is_indexable && $seo->robots === 'index,follow') {
                    continue;
                }
                $before = ['is_indexable' => (bool) $article->is_indexable,
                    'sitemap_eligible' => (bool) $article->sitemap_eligible, 'llms_eligible' => (bool) $article->llms_eligible,
                    'seo_is_indexable' => (bool) $seo->is_indexable, 'robots' => $seo->robots];
                // Metadata only: preserve reviewed copy, source links, pointers, schema, media and publication clocks.
                DB::table('articles')->where('org_id', 0)->where('id', $article->id)->where('locale', 'zh-CN')
                    ->update(['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true]);
                DB::table('article_seo_meta')->where('id', $seo->id)->where('org_id', 0)->where('locale', 'zh-CN')
                    ->update(['is_indexable' => true, 'robots' => 'index,follow']);
                app(AuditLogger::class)->log(
                    Request::create('/ops/articles/reviewed-chinese-discoverability', 'POST'),
                    'reviewed_chinese_article_discoverability_activated', 'article', (string) $article->id,
                    meta: ['locale' => 'zh-CN', 'slug' => $article->slug, 'published_revision_id' => $article->published_revision_id,
                        'before' => $before, 'changed_fields' => ['is_indexable', 'sitemap_eligible', 'llms_eligible', 'seo.is_indexable', 'seo.robots']],
                    reason: 'activate_existing_reviewed_c07_c11_chinese_articles',
                );
            }

            return $targets !== [];
        });

        // A prior post-commit cache failure must still be repairable without repeating data writes or audit.
        if ($hasQualifiedTargets) {
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
        }
    }

    public function down(): void
    {
        // Previous application code supports these metadata fields. Code rollback does not withdraw approved content.
    }
};
