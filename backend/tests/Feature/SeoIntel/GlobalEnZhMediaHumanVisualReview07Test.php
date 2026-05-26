<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhMediaHumanVisualReview07Test extends TestCase
{
    #[Test]
    public function media_review_packet_requires_visual_review_without_upload_or_replacement(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-media-human-visual-review-07.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-media-human-visual-review-07.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('global-en-zh-media-human-visual-review-07.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-MEDIA-HUMAN-VISUAL-REVIEW-07', $generated['task'] ?? null);
        $this->assertSame(
            'media_human_visual_review_decision_packet_created_pending_ocr_and_review',
            $generated['final_decision'] ?? null,
        );

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertCount(30, $decisions);
        $this->assertSame(30, $generated['summary']['total_items'] ?? null);
        $this->assertSame(30, $generated['summary']['human_visual_review_required'] ?? null);
        $this->assertSame(29, $generated['summary']['ocr_required'] ?? null);
        $this->assertSame(29, $generated['summary']['embedded_text_review_required'] ?? null);
        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);
        $this->assertSame(0, $generated['summary']['import_ready'] ?? null);
        $this->assertSame(0, $generated['summary']['replacement_performed'] ?? null);
        $this->assertSame(0, $generated['summary']['media_upload_performed'] ?? null);
        $this->assertSame(0, $generated['summary']['media_generation_performed'] ?? null);
        $this->assertSame(0, $generated['summary']['media_replacement_performed'] ?? null);

        foreach ($decisions as $decision) {
            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertTrue($decision['human_visual_review_required'] ?? false);
            $this->assertFalse($decision['ocr_completed'] ?? true);
            $this->assertFalse($decision['publish_ready'] ?? true);
            $this->assertFalse($decision['import_ready'] ?? true);
            $this->assertFalse($decision['replacement_performed'] ?? true);
            $this->assertFalse($decision['media_upload_performed'] ?? true);
            $this->assertFalse($decision['media_generation_performed'] ?? true);
            $this->assertFalse($decision['media_replacement_performed'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertNotEmpty($decision['required_reviewer_roles'] ?? []);
        }

        $policy = $generated['visual_review_policy'] ?? [];
        $this->assertTrue($policy['do_not_upload_images'] ?? false);
        $this->assertTrue($policy['do_not_generate_images'] ?? false);
        $this->assertTrue($policy['do_not_replace_media'] ?? false);
        $this->assertTrue($policy['do_not_assert_ocr_complete_without_ocr'] ?? false);
        $this->assertTrue($policy['draft_review_only_until_human_visual_review'] ?? false);

        $evidence = $generated['browser_cms_read_only_evidence'] ?? [];
        $this->assertFalse($evidence['cms_mutation_performed'] ?? true);
        $this->assertFalse($evidence['browser_write_actions_performed'] ?? true);
        $this->assertFalse($evidence['private_user_data_accessed'] ?? true);
        $this->assertFalse($evidence['media_upload_performed'] ?? true);
        $this->assertFalse($evidence['media_generation_performed'] ?? true);
        $this->assertFalse($evidence['media_replacement_performed'] ?? true);
        $this->assertFalse($evidence['ocr_completed'] ?? true);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-CMS-IMPORT-01', $generated['next_task'] ?? null);
    }
}
