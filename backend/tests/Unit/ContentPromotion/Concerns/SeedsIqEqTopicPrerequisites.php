<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion\Concerns;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\IqPublicArticlePackage;

trait SeedsIqEqTopicPrerequisites
{
    private function seedArticles(): void
    {
        $rows = app(IqPublicArticlePackage::class)->read(base_path(), IqPublicArticlePackage::SHA256);
        $articles = [];
        foreach ($rows as $row) {
            $article = Article::query()->withoutGlobalScopes()->create([
                ...$row['identity'], 'title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'],
                'content_md' => $row['snapshot']['content_md'], 'status' => 'published', 'is_public' => true,
                'translation_group_id' => 'fixture-'.$row['page_id'], 'is_indexable' => false, 'source_locale' => 'zh-CN', 'translation_status' => $row['identity']['locale'] === 'en' ? 'reviewed' : 'source',
                'published_at' => now()->subMinute(),
            ]);
            $article->forceFill(['source_version_hash' => $article->computeSourceVersionHash()])->saveQuietly();
            $articles[$row['identity']['locale'].':'.$row['identity']['slug']] = $article;
        }
        foreach ($rows as $row) {
            $article = $articles[$row['identity']['locale'].':'.$row['identity']['slug']];
            $source = $articles['zh-CN:'.$row['identity']['slug']];
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source->id, 'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale,
                'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published', 'published_at' => now(),
                'source_version_hash' => $source->computeSourceVersionHash(), 'translated_from_version_hash' => $source->computeSourceVersionHash(),
                'authority_package_sha256' => IqPublicArticlePackage::SHA256, 'authority_source_package' => IqPublicArticlePackage::PACKAGE,
                'authority_asset_key' => $article->locale.':'.$article->slug, 'authority_source_hash' => $row['snapshot_sha256'],
                'authority_metadata_json' => ['snapshot' => $row['snapshot']], ...$row['snapshot'],
            ]);
            $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();
            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale, 'seo_title' => $row['snapshot']['seo_title'],
                'seo_description' => $row['snapshot']['seo_description'], 'og_title' => $row['snapshot']['seo_title'], 'og_description' => $row['snapshot']['seo_description'],
                'schema_json' => ['editorial_package_v1' => ['iq_article_exact_package_v1' => ['package_sha256' => IqPublicArticlePackage::SHA256,
                    'revision_id' => (int) $revision->id, 'snapshot_sha256' => $row['snapshot_sha256']], 'answer_surface_v1' => ['faq_items' => $row['snapshot']['faq_items']]]],
            ]);
        }
    }

    private function seedIqEntry(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            $record = \Illuminate\Support\Facades\DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->first();
            if (! $record) {
                continue;
            }
            $content = json_decode($record->content_i18n_json, true) ?: [];
            foreach (app(\App\Services\ContentPromotion\IqPublicEntryPackage::class)->read(base_path(), \App\Services\ContentPromotion\IqPublicEntryPackage::SHA256) as $row) {
                $content[$row['key']] = array_replace($content[$row['key']] ?? [], $row['patch']);
            }
            \Illuminate\Support\Facades\DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->update(['content_i18n_json' => json_encode($content, JSON_THROW_ON_ERROR)]);
        }
        \Illuminate\Support\Facades\Cache::flush();
    }
}
