<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhHumanReviewImportQueue00Test extends TestCase
{
    #[Test]
    public function consolidated_review_queue_exists_and_is_decision_only(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-human-review-import-queue-00.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-human-review-import-queue-00.v1.json');
        $queuePath = base_path('docs/seo/generated/global-en-zh-human-review-queue.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);
        $this->assertFileExists($queuePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $queue = json_decode((string) file_get_contents($queuePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-human-review-import-queue-00.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-HUMAN-REVIEW-IMPORT-QUEUE-00', $generated['task'] ?? null);
        $this->assertSame('human_review_import_queue_created_ready_for_family_decision_packets', $generated['final_decision'] ?? null);

        $items = $generated['review_queue'] ?? [];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertSame($generated['total_review_items'] ?? null, count($items));
        $this->assertSame($queue['total_review_items'] ?? null, count($queue['review_queue'] ?? []));

        foreach ([
            'content_help_policy_pages',
            'articles',
            'topics_test_landing',
            'research_pages',
            'career_content',
            'result_report_assets',
            'media_assets',
            'global_ui_i18n',
        ] as $family) {
            $this->assertArrayHasKey($family, $generated['asset_family_counts'] ?? []);
        }

        $first = $items[0];
        foreach ([
            'asset_family',
            'asset_key',
            'source_authority',
            'draft_exists',
            'CMS_current_state',
            'public_runtime_state',
            'human_review_required',
            'claim_review_required',
            'legal_review_required',
            'factual_review_required',
            'visual_review_required',
            'privacy_review_required',
            'clinical_review_required',
            'career_claim_review_required',
            'import_ready',
            'publish_ready',
            'blocked',
            'deferred',
            'reason',
            'recommended_import_wave',
            'required_reviewer_role',
            'sitemap_eligible_after_import',
            'llms_eligible_after_import',
            'footer_eligible_after_import',
            'search_channel_eligible_after_import',
            'notes',
        ] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $first);
        }

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertFalse($item['publish_ready'] ?? true);
            $this->assertFalse($item['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($item['llms_eligible_after_import'] ?? true);
            $this->assertFalse($item['footer_eligible_after_import'] ?? true);
            $this->assertFalse($item['search_channel_eligible_after_import'] ?? true);
            $this->assertNotEmpty($item['required_reviewer_role'] ?? []);
        }

        $evidence = $generated['browser_cms_read_only_evidence'] ?? [];
        $this->assertFalse($evidence['cms_mutation_performed'] ?? true);
        $this->assertFalse($evidence['browser_write_actions_performed'] ?? true);
        $this->assertFalse($evidence['private_user_data_accessed'] ?? true);
        $this->assertNotEmpty($evidence['public_site_observations'] ?? []);
        $this->assertNotEmpty($evidence['cms_ops_observations'] ?? []);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVIEW-IMPORT-01', $generated['next_task'] ?? null);
    }
}
