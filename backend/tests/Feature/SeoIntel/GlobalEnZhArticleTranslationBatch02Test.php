<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhArticleTranslationBatch02Test extends TestCase
{
    #[Test]
    public function article_translation_package_is_draft_only_and_review_gated(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-article-translation-batch-02.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-article-translation-batch-02.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-article-translation-batch-02.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-ARTICLE-TRANSLATION-BATCH-02', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-article-translation-batch-02.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('draft_import_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertIsArray($items);
        $this->assertCount(6, $items);

        $expectedSlugs = [
            'are-infj-men-rare-or-socially-silenced',
            'best-valentines-date-by-personality-and-relationship-science',
            'childhood-dream-job-still-shapes-career-choice',
            'how-16-personality-types-talk-to-an-ai-coach',
            'how-personality-shapes-attitude-toward-ai',
            'which-love-script-fits-you-best',
        ];

        $this->assertEqualsCanonicalizing($expectedSlugs, array_column($items, 'source_zh_slug'));
        $this->assertEqualsCanonicalizing($expectedSlugs, array_column($items, 'proposed_en_slug'));

        foreach ($items as $item) {
            $this->assertSame('articles', $item['asset_family'] ?? null);
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertTrue($item['claim_review_required'] ?? false);
            $this->assertTrue($item['reference_review_required'] ?? false);
            $this->assertFalse($item['unsupported_claims_added'] ?? true);
            $this->assertSame('draft_import_only', $item['publish_state'] ?? null);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['footer_or_nav_eligible'] ?? true);
            $this->assertFalse($item['jsonld_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertNotEmpty($item['title_en_draft'] ?? '');
            $this->assertNotEmpty($item['description_en_draft'] ?? '');
            $this->assertNotEmpty($item['h1_en_draft'] ?? '');
            $this->assertNotEmpty($item['summary_en_draft'] ?? '');
            $this->assertNotEmpty($item['body_blocks_en_draft'] ?? []);
            $this->assertNotEmpty($item['content_md_en_draft'] ?? '');
            $this->assertNotEmpty($item['source_references_preserved'] ?? []);
        }

        $this->assertSame(6, $generated['missing_en_article_count'] ?? null);
        $this->assertSame(6, $generated['draft_article_count'] ?? null);
        $this->assertSame(0, $generated['deferred_count'] ?? null);
        $this->assertSame(0, $generated['blocked_count'] ?? null);
        $this->assertSame(6, $generated['human_review_required_count'] ?? null);
        $this->assertSame(6, $generated['claim_review_required_count'] ?? null);
        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-TOPIC-TEST-LANDING-BATCH-03', $generated['next_task'] ?? null);
    }
}
