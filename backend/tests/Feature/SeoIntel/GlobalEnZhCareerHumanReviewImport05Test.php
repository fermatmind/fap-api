<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhCareerHumanReviewImport05Test extends TestCase
{
    #[Test]
    public function career_review_packet_blocks_placeholder_pages_and_overclaim(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-career-human-review-import-05.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-career-human-review-import-05.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('global-en-zh-career-human-review-import-05.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CAREER-HUMAN-REVIEW-IMPORT-05', $generated['task'] ?? null);
        $this->assertSame('career_human_review_decision_packet_created_with_translation_group_blockers', $generated['final_decision'] ?? null);

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertCount(415, $decisions);
        $this->assertSame(36, $generated['summary']['career_guide_count'] ?? null);
        $this->assertSame(378, $generated['summary']['career_job_count'] ?? null);
        $this->assertSame(378, $generated['summary']['deferred_translation_group_required'] ?? null);
        $this->assertSame(0, $generated['summary']['placeholder_pages_allowed'] ?? null);
        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);

        foreach ($decisions as $decision) {
            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertTrue($decision['career_claim_review_required'] ?? false);
            $this->assertTrue($decision['no_placeholder_page'] ?? false);
            $this->assertFalse($decision['publish_ready'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertFalse($decision['pseo_eligible_after_import'] ?? true);
            $this->assertNotEmpty($decision['required_reviewer_roles'] ?? []);
        }

        $this->assertContains('no best career', $generated['claim_boundary_policy'] ?? []);
        $this->assertContains('no hiring fit', $generated['claim_boundary_policy'] ?? []);
        $this->assertNotEmpty($generated['blocked_items'] ?? []);

        $evidence = $generated['browser_cms_read_only_evidence'] ?? [];
        $this->assertFalse($evidence['cms_mutation_performed'] ?? true);
        $this->assertFalse($evidence['browser_write_actions_performed'] ?? true);
        $this->assertFalse($evidence['private_user_data_accessed'] ?? true);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-RESULT-REPORT-HUMAN-REVIEW-IMPORT-06', $generated['next_task'] ?? null);
    }
}
