<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AlipayOwnerMismatchControlledRepair04Test extends TestCase
{
    #[Test]
    public function controlled_repair_artifacts_record_state_sync_only_boundaries(): void
    {
        $technicalIndexPath = dirname(base_path()).'/docs/commerce/payment-email-result-access-technical-index.md';
        $generatedPath = base_path('docs/commerce/generated/alipay-owner-mismatch-controlled-repair-04.v1.json');

        $this->assertFileExists($technicalIndexPath);
        $this->assertFileExists($generatedPath);
        $technicalIndex = (string) file_get_contents($technicalIndexPath);
        $this->assertStringContainsString('PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04', $technicalIndex);
        $this->assertStringContainsString('state-sync-only repair', $technicalIndex);
        $this->assertStringContainsString('did not create grants', $technicalIndex);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('alipay-owner-mismatch-controlled-repair-04.v1', $generated['schema_version'] ?? null);
        $this->assertSame('PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04', $generated['task'] ?? null);
        $this->assertTrue($generated['production_write_executed'] ?? false);
        $this->assertTrue($generated['dry_run_first'] ?? false);
        $this->assertTrue($generated['dry_run_passed'] ?? false);

        $preflight = $generated['preflight'] ?? [];
        $this->assertSame(8, $preflight['base_owner_mismatch_paid_no_grant_count'] ?? null);
        $this->assertSame(1, $preflight['state_sync_candidate_count'] ?? null);
        $this->assertSame(1, $preflight['active_grant_by_attempt_id_count'] ?? null);
        $this->assertSame(1, $preflight['ready_projection_by_attempt_id_count'] ?? null);
        $this->assertSame(7, $preflight['human_review_required_count'] ?? null);
        $this->assertFalse($preflight['raw_identifiers_printed'] ?? true);

        $repair = $generated['controlled_repair'] ?? [];
        $this->assertTrue($repair['transactional'] ?? false);
        $this->assertTrue($repair['row_lock_used'] ?? false);
        $this->assertSame(1, $repair['updated_count'] ?? null);
        $this->assertSame('granted', $repair['set_grant_state'] ?? null);
        $this->assertSame('fulfilled', $repair['set_status'] ?? null);

        $postRepair = $generated['post_repair'] ?? [];
        $this->assertSame(7, $postRepair['owner_mismatch_paid_no_grant_count'] ?? null);
        $this->assertSame(0, $postRepair['state_sync_candidate_count'] ?? null);
        $this->assertSame(7, $postRepair['remaining_human_review_required_count'] ?? null);

        $this->assertTrue($generated['no_benefit_grant_creation'] ?? false);
        $this->assertTrue($generated['no_projection_mutation'] ?? false);
        $this->assertTrue($generated['no_manual_pending_compensation'] ?? false);
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
