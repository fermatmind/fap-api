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
 * Reuse the existing native content approvals for this exact English published object.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 */
return new class extends Migration
{
    private const TARGETS = [
        259 => ['slug' => 'eq60-score-and-results-guide', 'revision_id' => 853, 'source_locale' => 'zh-CN'],
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
                    ->whereKey($articleId)->where('locale', 'en')->where('slug', $slug)->lockForUpdate()->get();
                if ($rows->isEmpty()) {
                    continue; // Staging/fresh environments must not release a different object sharing this slug.
                }
                if ($rows->count() !== 1) {
                    throw new RuntimeException('Reviewed English EQ article identity is ambiguous: '.$slug);
                }
                $article = $rows->sole();
                $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->published_revision_id)->lockForUpdate()->first();
                $seoRows = ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
                    ->where('article_id', $article->id)->where('locale', 'en')->lockForUpdate()->get();
                $seo = $seoRows->count() === 1 ? $seoRows->sole() : null;
                $canonical = '/en/articles/'.$slug;

                if ($article->status !== 'published' || ! $article->is_public || $article->trashed()
                    || $article->lifecycle_state !== Article::LIFECYCLE_ACTIVE || filled($article->lifecycle_note)
                    || ! $article->published_at || $article->published_at->isFuture()
                    || (int) $article->published_revision_id !== $target['revision_id']
                    || (int) $article->working_revision_id !== $target['revision_id']
                    || $article->source_version_hash !== 'e5e5e0d60effada77acaa77e3e425314eab64a010223a60268ded34f62bc9edf'
                    || ! $revision || (int) $revision->org_id !== 0 || (int) $revision->article_id !== (int) $article->id
                    || $revision->locale !== 'en' || $revision->revision_status !== 'published'
                    || ! $revision->approved_at || ! $revision->published_at || $revision->published_at->isFuture()
                    || trim((string) $revision->content_md) === ''
                    || ! hash_equals(hash('sha256', (string) $revision->content_md), hash('sha256', (string) $article->content_md))
                    || ! $seo || $seo->canonical_url !== $canonical
                    || ! $reviews->hasApprovedEvidence('article', $article)
                    || ! $reviews->hasApprovedEvidence('article_translation_revision', $revision)) {
                    throw new RuntimeException('Reviewed English EQ article is not a current approved public revision: '.$slug);
                }
                $source = Article::withoutGlobalScopes()->withTrashed()->whereKey($article->source_article_id)->lockForUpdate()->first();
                $sourceRevision = $source ? ArticleTranslationRevision::withoutGlobalScopes()->whereKey($source->published_revision_id)->lockForUpdate()->first() : null;
                if (! $source || (int) $source->org_id !== 0 || $source->locale !== $sourceLocale || $source->slug !== $slug
                    || (int) $source->id !== 254 || (int) $source->published_revision_id !== 785
                    || $article->source_locale !== 'zh-CN'
                    || $source->status !== 'published' || ! $source->is_public || $source->trashed()
                    || $source->lifecycle_state !== Article::LIFECYCLE_ACTIVE
                    || ! $source->published_at || $source->published_at->isFuture()
                    || ! $sourceRevision || (int) $sourceRevision->org_id !== 0 || (int) $sourceRevision->article_id !== (int) $source->id
                    || $sourceRevision->locale !== $sourceLocale || $sourceRevision->revision_status !== 'published'
                    || trim((string) $sourceRevision->content_md) === ''
                    || ! hash_equals(hash('sha256', (string) $sourceRevision->content_md), hash('sha256', (string) $source->content_md))
                    || ! $sourceRevision->approved_at || ! $sourceRevision->published_at || $sourceRevision->published_at->isFuture()
                    || ! $source->translation_group_id || $source->translation_group_id !== $article->translation_group_id
                    || $source->source_version_hash !== 'fe925c3d5e7ae823f16bb457fb5b39704129fe7e8766584f5266ec8fa10eed71' || $source->source_version_hash !== $revision->translated_from_version_hash
                    || $source->source_version_hash !== $article->translated_from_version_hash
                    || ! $reviews->hasApprovedEvidence('article', $source)
                    || ! $reviews->hasApprovedEvidence('article_translation_revision', $sourceRevision)) {
                    throw new RuntimeException('Reviewed English EQ article translation source is stale: '.$slug);
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
                DB::table('articles')->where('org_id', 0)->where('id', $article->id)->where('locale', 'en')
                    ->update(['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true]);
                DB::table('article_seo_meta')->where('id', $seo->id)->where('org_id', 0)->where('locale', 'en')
                    ->update(['is_indexable' => true, 'robots' => 'index,follow']);
                app(AuditLogger::class)->log(
                    Request::create('/ops/articles/reviewed-english-eq-discoverability', 'POST'),
                    'reviewed_english_eq_article_discoverability_activated', 'article', (string) $article->id,
                    meta: ['locale' => 'en', 'slug' => $article->slug, 'published_revision_id' => $article->published_revision_id,
                        'before' => $before, 'changed_fields' => ['is_indexable', 'sitemap_eligible', 'llms_eligible', 'seo.is_indexable', 'seo.robots']],
                    reason: 'activate_existing_reviewed_c07_english_article',
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
