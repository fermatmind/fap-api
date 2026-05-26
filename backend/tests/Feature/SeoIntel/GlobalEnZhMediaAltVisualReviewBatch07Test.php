<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhMediaAltVisualReviewBatch07Test extends TestCase
{
    #[Test]
    public function media_alt_visual_review_package_does_not_upload_replace_or_claim_ocr_complete(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-media-alt-visual-review-batch-07.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-media-alt-visual-review-batch-07.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-media-alt-visual-review-batch-07.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-MEDIA-ALT-VISUAL-REVIEW-BATCH-07', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-media-alt-visual-review-batch-07.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('media_alt_visual_review_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertCount(30, $items);
        $this->assertSame(30, $generated['total_items'] ?? null);
        $this->assertSame(3, $generated['media_library_item_count'] ?? null);
        $this->assertSame(26, $generated['article_cover_item_count'] ?? null);
        $this->assertSame(1, $generated['career_guide_social_image_item_count'] ?? null);
        $this->assertSame(72, $generated['career_guide_missing_social_image_rows'] ?? null);
        $this->assertSame(6, $generated['missing_en_cover_alt_draft_count'] ?? null);
        $this->assertSame(29, $generated['ocr_required_count'] ?? null);
        $this->assertSame(0, $generated['ocr_completed_count'] ?? null);

        foreach ($items as $item) {
            $this->assertSame('draft_review_only', $item['publish_state'] ?? null);
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertTrue($item['human_visual_review_required'] ?? false);
            $this->assertFalse($item['ocr_completed'] ?? true);
            $this->assertFalse($item['replacement_required'] ?? true);
            $this->assertFalse($item['replacement_performed'] ?? true);
            $this->assertFalse($item['media_upload_performed'] ?? true);
            $this->assertFalse($item['media_generation_performed'] ?? true);
            $this->assertFalse($item['media_replacement_performed'] ?? true);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertNotEmpty($item['image_url_or_asset_ref'] ?? []);
        }

        $missingAltKeys = $generated['missing_en_alt_asset_keys'] ?? [];
        $this->assertCount(6, $missingAltKeys);
        $this->assertContains('article_cover:are-infj-men-rare-or-socially-silenced', $missingAltKeys);
        $this->assertContains('article_cover:best-valentines-date-by-personality-and-relationship-science', $missingAltKeys);
        $this->assertContains('article_cover:childhood-dream-job-still-shapes-career-choice', $missingAltKeys);
        $this->assertContains('article_cover:how-16-personality-types-talk-to-an-ai-coach', $missingAltKeys);
        $this->assertContains('article_cover:how-personality-shapes-attitude-toward-ai', $missingAltKeys);
        $this->assertContains('article_cover:which-love-script-fits-you-best', $missingAltKeys);

        $careerItem = array_values(array_filter($items, fn (array $item): bool => ($item['asset_key'] ?? null) === 'career_guides:og_and_twitter_images'))[0];
        $this->assertSame('72_missing_authority_social_image_rows', $careerItem['og_image_status'] ?? null);
        $this->assertFalse($careerItem['ocr_required'] ?? true);
        $this->assertCount(72, $careerItem['subitems'] ?? []);
        foreach ($careerItem['subitems'] as $subitem) {
            $this->assertNotEmpty($subitem['guide_slug'] ?? '');
            $this->assertContains($subitem['locale'] ?? null, ['en', 'zh-CN']);
            $this->assertSame(['og_image_url', 'twitter_image_url'], $subitem['missing_slots'] ?? null);
            $this->assertTrue($subitem['human_visual_review_required'] ?? false);
            $this->assertTrue($subitem['ocr_required_after_image_assignment'] ?? false);
        }

        $this->assertSame(0, $generated['media_upload_performed_count'] ?? null);
        $this->assertSame(0, $generated['media_generation_performed_count'] ?? null);
        $this->assertSame(0, $generated['media_replacement_performed_count'] ?? null);
        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['search_channel_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertTrue($generated['no_media_upload'] ?? false);
        $this->assertTrue($generated['no_media_generation'] ?? false);
        $this->assertTrue($generated['no_media_replacement'] ?? false);
        $this->assertTrue($generated['ocr_not_performed'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-GLOBAL-UI-I18N-BATCH-08', $generated['next_task'] ?? null);
    }
}
