<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AlipayOwnerMismatchReview03Test extends TestCase
{
    #[Test]
    public function owner_mismatch_review_artifacts_exist_with_closed_write_gates(): void
    {
        $technicalIndexPath = dirname(base_path()).'/docs/commerce/payment-email-result-access-technical-index.md';
        $generatedPath = base_path('docs/commerce/generated/alipay-owner-mismatch-review-03.v1.json');

        $this->assertFileExists($technicalIndexPath);
        $this->assertFileExists($generatedPath);
        $technicalIndex = (string) file_get_contents($technicalIndexPath);
        $this->assertStringContainsString('PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03', $technicalIndex);
        $this->assertStringContainsString('ATTEMPT_OWNER_MISMATCH', $technicalIndex);
        $this->assertStringContainsString('automatic_repair_forbidden', $technicalIndex);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('alipay-owner-mismatch-review-03.v1', $generated['schema_version'] ?? null);
        $this->assertSame('PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03', $generated['task'] ?? null);
        $this->assertSame('ATTEMPT_OWNER_MISMATCH', $generated['owner_mismatch_error_code'] ?? null);
        $this->assertSame(8, $generated['paid_no_grant_current_count'] ?? null);

        $summary = $generated['classification_summary'] ?? [];
        $this->assertSame(1, $summary['safe_order_grant_state_sync_after_approval'] ?? null);
        $this->assertSame(7, $summary['human_ownership_review_required'] ?? null);
        $this->assertSame(7, $summary['automatic_repair_forbidden'] ?? null);
        $this->assertTrue($summary['confirmed_true_owner_mismatch_repair_forbidden'] ?? false);

        $items = $generated['classified_items'] ?? [];
        $this->assertCount(8, $items);
        $this->assertCount(1, array_filter(
            $items,
            static fn (array $item): bool => ($item['classification'] ?? null) === 'safe_order_grant_state_sync_after_approval'
        ));
        $this->assertCount(7, array_filter(
            $items,
            static fn (array $item): bool => ($item['classification'] ?? null) === 'human_ownership_review_required'
        ));

        foreach ($items as $item) {
            $this->assertSame('ATTEMPT_OWNER_MISMATCH', $item['payment_event_error_code'] ?? null);
            $this->assertFalse($item['future_write_allowed_without_new_approval'] ?? true);
        }

        $schedulerPolicy = $generated['automatic_scheduler_policy'] ?? [];
        $this->assertFalse($schedulerPolicy['owner_mismatch_auto_grant_allowed'] ?? true);
        $this->assertFalse($schedulerPolicy['owner_mismatch_auto_projection_allowed'] ?? true);
        $this->assertFalse($schedulerPolicy['owner_mismatch_auto_state_sync_allowed'] ?? true);

        $this->assertTrue($generated['no_production_repair'] ?? false);
        $this->assertTrue($generated['no_manual_compensation'] ?? false);
        $this->assertTrue($generated['no_raw_log_read'] ?? false);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_fap_web_change'] ?? false);
        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);
    }
}
