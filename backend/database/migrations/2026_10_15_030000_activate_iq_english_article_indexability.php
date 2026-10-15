<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUGS = [
        'iq-test-score-and-limits-explained',
        'iq-test-tool-guide',
        'iq-test-narrative-portrait',
        'iq-test-growth-guide',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        $changed = DB::transaction(function (): bool {
            $articles = DB::table('articles')->where('org_id', 0)->where('locale', 'en')
                ->whereIn('slug', self::SLUGS)->orderBy('id')->lockForUpdate()->get();
            if ($articles->isEmpty()) {
                return false; // A fresh database has no existing article to activate.
            }
            if ($articles->count() !== count(self::SLUGS)) {
                throw new RuntimeException('IQ English article set is incomplete; refusing partial activation.');
            }

            $targets = [];
            foreach ($articles as $article) {
                $revision = DB::table('article_translation_revisions')->where('id', $article->published_revision_id)->lockForUpdate()->first();
                $source = DB::table('articles')->where('id', $article->source_article_id)->lockForUpdate()->first();
                $seo = DB::table('article_seo_meta')->where('org_id', 0)->where('article_id', $article->id)->where('locale', 'en')->lockForUpdate()->first();
                $canonical = 'https://fermatmind.com/en/articles/'.$article->slug;

                if ($article->status !== 'published' || ! $article->is_public
                    || ! $revision || (int) $revision->article_id !== (int) $article->id || (int) $revision->org_id !== 0
                    || $revision->locale !== 'en' || $revision->revision_status !== 'published'
                    || ! $revision->approved_at || ! $revision->published_at || $revision->published_at > now()->toDateTimeString()
                    || trim((string) $revision->content_md) === ''
                    || ! $source || (int) $source->org_id !== 0 || $source->locale !== 'zh-CN' || $source->slug !== $article->slug
                    || $source->status !== 'published' || ! $source->is_public
                    || ! $article->translation_group_id || $article->translation_group_id !== $source->translation_group_id
                    || ! $source->source_version_hash || ! $revision->translated_from_version_hash
                    || ! hash_equals($source->source_version_hash, $revision->translated_from_version_hash)
                    || ! $seo || ($seo->canonical_url && $seo->canonical_url !== $canonical)) {
                    throw new RuntimeException('IQ English article is not a current approved public translation: '.$article->slug);
                }
                $targets[] = [$article, $seo];
            }

            $before = [];
            foreach ($targets as [$article, $seo]) {
                if ($article->is_indexable && $article->sitemap_eligible && $article->llms_eligible
                    && $seo->is_indexable && $seo->robots === 'index,follow') {
                    continue;
                }
                $before[] = ['slug' => $article->slug, 'is_indexable' => (bool) $article->is_indexable,
                    'sitemap_eligible' => (bool) $article->sitemap_eligible, 'llms_eligible' => (bool) $article->llms_eligible,
                    'seo_is_indexable' => (bool) $seo->is_indexable, 'robots' => $seo->robots];
                // Preserve copy, publication clocks, source bindings and working/published pointers.
                DB::table('articles')->where('id', $article->id)->where('org_id', 0)->where('locale', 'en')
                    ->update(['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true]);
                DB::table('article_seo_meta')->where('id', $seo->id)->where('org_id', 0)->where('locale', 'en')
                    ->update(['is_indexable' => true, 'robots' => 'index,follow']);
            }
            if ($before === []) {
                return false;
            }
            app(AuditLogger::class)->log(
                Request::create('/ops/articles/iq-english-discoverability', 'POST'),
                'iq_english_article_discoverability_activated',
                'article',
                meta: ['actor' => 'automatic_trunk_migration', 'locale' => 'en', 'before' => $before,
                    'changed_fields' => ['is_indexable', 'sitemap_eligible', 'llms_eligible', 'seo.is_indexable', 'seo.robots']],
                reason: 'activate_existing_approved_iq_english_articles',
            );

            return true;
        });

        if ($changed) {
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
        }
    }

    public function down(): void
    {
        // Metadata is compatible with the previous application release. Never
        // withdraw approved public content automatically during code rollback.
    }
};
