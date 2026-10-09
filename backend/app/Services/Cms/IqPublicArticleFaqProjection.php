<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\IqPublicArticlePackage;
use App\Services\ContentPromotion\PromotionContextFactory;

/** Complete FAQ projection only for the fixed, published IQ package. */
final class IqPublicArticleFaqProjection
{
    public static function limit(Article $article, array $metadata, int $default): int
    {
        $binding = $metadata['iq_article_exact_package_v1'] ?? null;
        $revision = $article->publishedRevision;
        $snapshot = (array) data_get($revision?->authority_metadata_json, 'snapshot', []);
        $faqs = (array) data_get($metadata, 'answer_surface_v1.faq_items', []);
        if ((int) $article->org_id !== 0 || ! in_array($article->locale, ['en', 'zh-CN'], true)
            || ! in_array($article->slug, ['iq-test-score-and-limits-explained', 'iq-test-tool-guide', 'iq-test-narrative-portrait', 'iq-test-growth-guide', 'what-is-iq-and-how-it-is-measured'], true)
            || ! $article->is_public || $article->status !== 'published' || ! is_array($binding) || ! $revision
            || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
            || (int) $revision->article_id !== (int) $article->id || (int) $revision->org_id !== 0 || $revision->locale !== $article->locale
            || $revision->authority_package_sha256 !== IqPublicArticlePackage::SHA256
            || $revision->authority_asset_key !== $article->locale.':'.$article->slug
            || ($binding['package_sha256'] ?? null) !== IqPublicArticlePackage::SHA256
            || ($binding['revision_id'] ?? null) !== (int) $article->published_revision_id
            || ($binding['snapshot_sha256'] ?? null) !== $revision->authority_source_hash
            || ! is_string($binding['snapshot_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $binding['snapshot_sha256']) !== 1
            || ($snapshot['content_md'] ?? null) !== $revision->content_md || ! is_array($snapshot['faq_items'] ?? null) || PromotionContextFactory::canonicalJson($snapshot['faq_items'] ?? null) !== PromotionContextFactory::canonicalJson($faqs)
            || ! array_is_list($faqs) || count($faqs) < 1 || count($faqs) > 9) {
            return $default;
        }

        return count($faqs);
    }
}
