<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesHumanReviewImport01Test extends TestCase
{
    #[Test]
    public function content_page_review_decision_packet_is_decision_only(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-content-pages-human-review-import-01.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-human-review-import-01.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-pages-human-review-import-01.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVIEW-IMPORT-01', $generated['task'] ?? null);
        $this->assertSame('content_page_review_decision_packet_created_ready_for_human_review', $generated['final_decision'] ?? null);

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertIsArray($decisions);
        $this->assertCount(14, $decisions);

        $assetKeys = array_column($decisions, 'asset_key');
        foreach ([
            'brand',
            'charter',
            'foundation',
            'careers',
            'policies',
            'support',
            'about',
            'help-about',
            'help-contact',
            'help-faq',
            'help-for-business-and-research',
            'method-boundaries',
            'privacy',
            'terms',
        ] as $assetKey) {
            $this->assertContains($assetKey, $assetKeys);
        }

        foreach ($decisions as $decision) {
            foreach ([
                'decision',
                'go_human_review',
                'no_go_blocked',
                'needs_founder_review',
                'needs_legal_review',
                'needs_factual_source',
                'needs_claim_edit',
                'ready_for_controlled_import_later',
                'not_footer_eligible_until_runtime_200',
                'not_sitemap_llms_eligible_until_published_and_verified',
                'required_reviewer_roles',
                'recommended_import_wave',
            ] as $requiredKey) {
                $this->assertArrayHasKey($requiredKey, $decision);
            }

            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertFalse($decision['publish_ready'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['footer_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertTrue($decision['not_footer_eligible_until_runtime_200'] ?? false);
            $this->assertTrue($decision['not_sitemap_llms_eligible_until_published_and_verified'] ?? false);
            $this->assertNotEmpty($decision['required_reviewer_roles'] ?? []);
        }

        $support = $decisions[array_search('support', $assetKeys, true)] ?? [];
        $this->assertTrue($support['blocked'] ?? false);
        $this->assertSame('NO-GO blocked', $support['decision'] ?? null);

        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);
        $this->assertContains('help-contact', $generated['recommended_first_controlled_import_wave'] ?? []);

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
        $this->assertSame('GLOBAL-EN-ZH-ARTICLE-HUMAN-REVIEW-IMPORT-02', $generated['next_task'] ?? null);
    }
}
