<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048RolloutDryRun01Test extends TestCase
{
    public function test_generated_dry_run_artifact_records_blockers_and_no_apply(): void
    {
        $path = base_path('docs/seo/generated/detail-ready-1048-rollout-dry-run-01.v1.json');

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('DETAIL_READY_1048_ROLLOUT_DRY_RUN-01', $payload['task']);
        $this->assertSame('detail_ready_1048_rollout_dry_run_blocked_pending_authority_scan', $payload['final_decision']);
        $this->assertSame(30, $payload['source_context']['current_public_detail_count']);
        $this->assertSame(1018, $payload['source_context']['ready_not_public_delta_count']);
        $this->assertSame(1048, $payload['source_context']['target_public_detail_total']);
        $this->assertSame(2036, $payload['source_context']['expected_delta_locale_rows']);
        $this->assertSame('detail_ready_1048', $payload['manifest_contract']['target']);
        $this->assertFalse($payload['manifest_contract']['apply_allowed']);
        $this->assertFalse($payload['manifest_contract']['rollout_apply_allowed']);
        $this->assertFalse($payload['manifest_contract']['writes_database']);
        $this->assertFalse($payload['apply_task_added_to_manifest']);
        $this->assertTrue($payload['future_apply_requires_explicit_user_approval']);
        $this->assertTrue($payload['no_cms_mutation']);
        $this->assertTrue($payload['no_deploy']);
        $this->assertTrue($payload['no_search_channel_action']);
        $this->assertTrue($payload['no_url_submission']);
        $this->assertTrue($payload['no_external_search_api_call']);
        $this->assertTrue($payload['no_pseo_generation']);
        $this->assertFalse($payload['runtime_promotion_performed']);
        $this->assertFalse($payload['database_write_performed']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_APPLY-01', $payload['next_task']);

        $blockerReasons = array_column($payload['blockers'], 'reason');
        $this->assertContains('authority_scan_unavailable_in_local_environment', $blockerReasons);
        $this->assertContains('explicit_1018_delta_slug_list_missing', $blockerReasons);
        $this->assertContains('rollout_manifest_not_generated', $blockerReasons);
        $this->assertContains('apply_requires_future_explicit_approval', $blockerReasons);
    }
}
