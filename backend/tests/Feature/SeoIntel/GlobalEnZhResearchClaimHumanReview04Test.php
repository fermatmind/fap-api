<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResearchClaimHumanReview04Test extends TestCase
{
    #[Test]
    public function research_claim_review_packet_blocks_schema_and_publish(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-research-claim-human-review-04.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-research-claim-human-review-04.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('global-en-zh-research-claim-human-review-04.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-RESEARCH-CLAIM-HUMAN-REVIEW-04', $generated['task'] ?? null);
        $this->assertSame('research_claim_review_decision_packet_created_with_blockers', $generated['final_decision'] ?? null);

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertCount(2, $decisions);

        foreach ($decisions as $decision) {
            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertTrue($decision['claim_review_required'] ?? false);
            $this->assertTrue($decision['blocked'] ?? false);
            $this->assertTrue($decision['deferred'] ?? false);
            $this->assertFalse($decision['dataset_schema_eligible'] ?? true);
            $this->assertFalse($decision['article_schema_eligible'] ?? true);
            $this->assertFalse($decision['schema_activation_allowed'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertFalse($decision['digital_pr_eligible_after_import'] ?? true);
            $this->assertFalse($decision['pseo_eligible_after_import'] ?? true);
            $this->assertNotEmpty($decision['forbidden_claim_hits'] ?? []);
            $this->assertNotEmpty($decision['required_caveats'] ?? []);
        }

        $this->assertSame(2, $generated['summary']['blocked'] ?? null);
        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);
        $this->assertContains('no MBTI predicts salary', $generated['forbidden_claim_policy'] ?? []);
        $this->assertContains('no MBTI predicts turnover', $generated['forbidden_claim_policy'] ?? []);

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
        $this->assertSame('GLOBAL-EN-ZH-CAREER-HUMAN-REVIEW-IMPORT-05', $generated['next_task'] ?? null);
    }
}
