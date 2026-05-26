<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhArticleHumanReviewImport02Test extends TestCase
{
    #[Test]
    public function article_review_decision_packet_is_decision_only(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-article-human-review-import-02.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-article-human-review-import-02.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-article-human-review-import-02.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-ARTICLE-HUMAN-REVIEW-IMPORT-02', $generated['task'] ?? null);
        $this->assertSame('article_review_decision_packet_created_ready_for_human_review', $generated['final_decision'] ?? null);

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertCount(6, $decisions);

        foreach ($decisions as $decision) {
            foreach ([
                'source_zh_article',
                'en_draft_package_key',
                'claim_risk',
                'factual_citation_risk',
                'media_alt_risk',
                'internal_link_intent',
                'publish_readiness_after_human_review',
                'sitemap_llms_eligibility_only_after_publish',
                'required_reviewer_roles',
            ] as $requiredKey) {
                $this->assertArrayHasKey($requiredKey, $decision);
            }

            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertTrue($decision['claim_review_required'] ?? false);
            $this->assertTrue($decision['reference_review_required'] ?? false);
            $this->assertTrue($decision['internal_link_review_required'] ?? false);
            $this->assertFalse($decision['publish_ready'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertTrue($decision['sitemap_llms_eligibility_only_after_publish'] ?? false);
        }

        $this->assertSame(6, $generated['summary']['claim_review_required'] ?? null);
        $this->assertSame(6, $generated['summary']['factual_citation_review_required'] ?? null);
        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);

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
        $this->assertSame('GLOBAL-EN-ZH-TOPIC-TEST-LANDING-HUMAN-REVIEW-IMPORT-03', $generated['next_task'] ?? null);
    }
}
