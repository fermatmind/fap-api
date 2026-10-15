<?php

declare(strict_types=1);

use App\Models\Article;
use App\Services\Cms\ArticleSeoGateRollout;
use App\Services\ContentPromotion\IqPublicArticlePackage;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUGS = [
        'iq-test-tool-guide',
        'iq-test-score-and-limits-explained',
        'iq-test-growth-guide',
        'iq-test-narrative-portrait',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        $changed = DB::transaction(function (): bool {
            $articles = Article::query()->withoutGlobalScopes()
                ->where('org_id', 0)->whereIn('slug', self::SLUGS)->whereIn('locale', ['en', 'zh-CN'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($articles->isEmpty()) {
                return false;
            }
            if ($articles->count() !== 8) {
                throw new RuntimeException('IQ hreflang requires all four published bilingual pairs.');
            }

            $expected = [];
            foreach ([IqPublicArticlePackage::SHA256, IqPublicArticlePackage::PARITY_SHA256] as $sha) {
                foreach (app(IqPublicArticlePackage::class)->read(base_path(), $sha) as $row) {
                    if (in_array($row['identity']['slug'], self::SLUGS, true)) {
                        $expected[$row['identity']['locale'].':'.$row['identity']['slug']] = [$row, $sha];
                    }
                }
            }

            // Lock and validate the complete reviewed set before changing any gate.
            foreach ($articles as $article) {
                $revision = $article->publishedRevision()->withoutGlobalScopes()->lockForUpdate()->first();
                $seo = $article->seoMeta()->withoutGlobalScopes()->lockForUpdate()->first();
                [$row, $sha] = $expected[$article->locale.':'.$article->slug];
                $path = '/'.($article->locale === 'en' ? 'en' : 'zh').'/articles/'.$article->slug;
                if ($article->status !== 'published' || ! $article->is_public || ! $article->is_indexable
                    || ! $article->sitemap_eligible || ! $article->llms_eligible
                    || ! $revision || (int) $revision->article_id !== (int) $article->id || (int) $revision->org_id !== 0 || $revision->locale !== $article->locale
                    || $revision->revision_status !== 'published' || ! $revision->approved_at || ! $revision->published_at
                    || $revision->published_at->isFuture() || $revision->translation_group_id !== $article->translation_group_id
                    || $revision->authority_package_sha256 !== $sha || $revision->authority_source_hash !== $row['snapshot_sha256']
                    || $revision->title !== $row['snapshot']['title'] || $revision->excerpt !== $row['snapshot']['excerpt']
                    || $revision->content_md !== $row['snapshot']['content_md']
                    || ! $seo || (int) $seo->org_id !== 0 || $seo->locale !== $article->locale || ! $seo->is_indexable
                    || $seo->robots !== 'index,follow'
                    || ($seo->canonical_url !== null && ! in_array($seo->canonical_url, [$path, 'https://fermatmind.com'.$path], true))
                    || $seo->seo_title !== $row['snapshot']['seo_title'] || $seo->seo_description !== $row['snapshot']['seo_description']) {
                    throw new RuntimeException('IQ hreflang published authority changed: '.$article->locale.':'.$article->slug);
                }
                $article->setRelation('publishedRevision', $revision)->setRelation('seoMeta', $seo);
            }

            $plans = [];
            foreach (self::SLUGS as $slug) {
                $source = $articles->first(fn (Article $article): bool => $article->slug === $slug && $article->locale === 'zh-CN');
                $english = $articles->first(fn (Article $article): bool => $article->slug === $slug && $article->locale === 'en');
                if (! $source || ! $english || ! $source->translation_group_id
                    || $source->translation_group_id !== $english->translation_group_id
                    || (int) $english->source_article_id !== (int) $source->id
                    || (int) $english->publishedRevision->source_article_id !== (int) $source->id
                    || ! $source->publishedRevision->source_version_hash
                    || $source->publishedRevision->source_version_hash !== $english->publishedRevision->translated_from_version_hash) {
                    throw new RuntimeException('IQ hreflang translation binding changed: '.$slug);
                }
                $held = collect([$source, $english])->filter(fn (Article $article): bool => data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled') !== true);
                if ($held->isEmpty()) {
                    continue;
                }
                $options = [
                    'article_ids' => $held->pluck('id')->implode(','),
                    'expected_slugs' => $held->pluck('slug')->implode(','),
                    'translation_group_id' => $source->translation_group_id,
                    'enable_hreflang' => true,
                    'no_publish' => true, 'no_search' => true, 'no_sitemap_llms_change' => true,
                    'no_content_change' => true, 'no_revalidation' => true,
                ];
                if (! app(ArticleSeoGateRollout::class)->run([...$options, 'dry_run' => true])['ok']) {
                    throw new RuntimeException('IQ hreflang reciprocal preflight failed: '.$slug);
                }
                $plans[] = $options;
            }

            foreach ($plans as $options) {
                if (! app(ArticleSeoGateRollout::class)->run([...$options, 'execute' => true])['ok']) {
                    throw new RuntimeException('IQ hreflang reciprocal activation failed.');
                }
            }

            return $plans !== [];
        });

        if ($changed) {
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches();
        }
    }

    public function down(): void
    {
        // Existing readers understand this metadata. A code rollback must not
        // withdraw verified language links or change any published revision.
    }
};
