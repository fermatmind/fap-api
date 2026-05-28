<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthorityReselect01Test extends TestCase
{
    public function test_reselect_report_blocks_package_when_no_eligible_replacement_exists(): void
    {
        $path = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-reselect-01.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1048_replacement_authority_reselect.v1', $payload['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_RESELECT-01', $payload['task'] ?? null);
        $this->assertSame('blocked_no_eligible_replacement_candidate', $payload['final_decision'] ?? null);

        $requirements = $payload['candidate_requirements'] ?? [];
        $this->assertTrue($requirements['must_be_outside_current_1048_union'] ?? false);
        $this->assertTrue($requirements['must_be_non_indexable'] ?? false);
        $this->assertTrue($requirements['must_not_be_manual_hold'] ?? false);
        $this->assertTrue($requirements['must_not_be_blocked'] ?? false);
        $this->assertTrue($requirements['must_not_be_cn_proxy'] ?? false);

        $summary = $payload['production_authority_scan_summary'] ?? [];
        $this->assertSame(1048, $summary['union_detail_ready'] ?? null);
        $this->assertSame(1018, $summary['ready_not_currently_public'] ?? null);
        $this->assertSame(0, $summary['outside_union_nonindexable_with_onet_soc_2019_crosswalk'] ?? null);
        $this->assertSame(0, $summary['outside_union_nonindexable_with_display_asset'] ?? null);

        $previous = $payload['previous_replacement_disqualification'] ?? [];
        $this->assertSame('computer-occupations-all-other', $previous['canonical_slug'] ?? null);
        $this->assertSame(2, $previous['existing_indexable_state_rows'] ?? null);
        $this->assertSame('already_indexable', $previous['disqualification_reason'] ?? null);

        $selection = $payload['candidate_selection_result'] ?? [];
        $this->assertFalse($selection['selected'] ?? true);
        $this->assertNull($selection['selected_slug'] ?? null);
        $this->assertFalse($selection['new_import_package_generated'] ?? true);
        $this->assertContains('legacy_career_jobs_not_valid_replacement_authority', $selection['blocked_on'] ?? []);

        foreach (['sitemap_eligible_in_this_pr', 'llms_eligible_in_this_pr', 'footer_eligible_in_this_pr', 'search_channel_eligible_in_this_pr', 'runtime_public_in_this_pr'] as $field) {
            $this->assertFalse($payload['exposure_policy'][$field] ?? true, $field);
        }

        foreach (['no_cms_mutation', 'no_database_write', 'no_runtime_promotion', 'no_publish', 'no_deploy', 'no_search_channel_action', 'no_url_submission', 'no_external_search_api_call', 'no_frontend_fallback_authority', 'no_pseo_generation'] as $field) {
            $this->assertTrue($payload[$field] ?? false, $field);
        }

        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01', $payload['next_task'] ?? null);
    }
}
