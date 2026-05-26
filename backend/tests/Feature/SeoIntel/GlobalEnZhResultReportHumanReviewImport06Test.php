<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResultReportHumanReviewImport06Test extends TestCase
{
    #[Test]
    public function result_report_review_packet_blocks_runtime_activation_and_zh_fallback(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-result-report-human-review-import-06.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-result-report-human-review-import-06.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('global-en-zh-result-report-human-review-import-06.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-RESULT-REPORT-HUMAN-REVIEW-IMPORT-06', $generated['task'] ?? null);
        $this->assertSame(
            'result_report_human_review_decision_packet_created_with_authority_export_blockers',
            $generated['final_decision'] ?? null,
        );

        $decisions = $generated['review_decisions'] ?? [];
        $this->assertCount(23, $decisions);
        $this->assertSame(23, $generated['summary']['total_items'] ?? null);
        $this->assertSame(0, $generated['summary']['publish_ready'] ?? null);
        $this->assertSame(0, $generated['summary']['runtime_activation_allowed_now'] ?? null);
        $this->assertSame(0, $generated['summary']['fallback_to_zh_allowed'] ?? null);
        $this->assertGreaterThan(0, $generated['summary']['blocked_items'] ?? 0);
        $this->assertGreaterThan(0, $generated['summary']['claim_review_required'] ?? 0);

        foreach ($decisions as $decision) {
            $this->assertTrue($decision['human_review_required'] ?? false);
            $this->assertTrue($decision['no_zh_fallback_required'] ?? false);
            $this->assertFalse($decision['fallback_to_zh_allowed'] ?? true);
            $this->assertFalse($decision['publish_ready'] ?? true);
            $this->assertFalse($decision['runtime_activation_allowed_now'] ?? true);
            $this->assertFalse($decision['sitemap_eligible_after_import'] ?? true);
            $this->assertFalse($decision['llms_eligible_after_import'] ?? true);
            $this->assertFalse($decision['search_channel_eligible_after_import'] ?? true);
            $this->assertNotEmpty($decision['required_reviewer_roles'] ?? []);
        }

        $this->assertNotEmpty($generated['blocked_items'] ?? []);
        $this->assertContains('no diagnosis', $generated['claim_boundary_policy'] ?? []);
        $this->assertContains('no treatment', $generated['claim_boundary_policy'] ?? []);
        $this->assertContains('no cure', $generated['claim_boundary_policy'] ?? []);

        $runtimeGuard = $generated['runtime_guard_policy'] ?? [];
        $this->assertTrue($runtimeGuard['en_result_report_cannot_silently_fallback_to_zh'] ?? false);
        $this->assertTrue($runtimeGuard['fap_web_clone_interpretation_copy_is_not_authority'] ?? false);
        $this->assertTrue($runtimeGuard['no_private_attempt_review'] ?? false);

        $evidence = $generated['browser_cms_read_only_evidence'] ?? [];
        $this->assertFalse($evidence['cms_mutation_performed'] ?? true);
        $this->assertFalse($evidence['browser_write_actions_performed'] ?? true);
        $this->assertFalse($evidence['private_user_data_accessed'] ?? true);
        $this->assertFalse($evidence['real_attempt_ids_accessed'] ?? true);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-MEDIA-HUMAN-VISUAL-REVIEW-07', $generated['next_task'] ?? null);
    }
}
