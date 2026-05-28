<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthorityImport01Test extends TestCase
{
    public function test_replacement_authority_import_package_is_review_only_and_not_exposed(): void
    {
        $generatedPath = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-import-01.v1.json');
        $packagePath = base_path('docs/seo/import-packages/detail-ready-1048-replacement-authority-import-01.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_IMPORT-01', $generated['task'] ?? null);
        $this->assertSame('replacement_authority_import_package_ready_for_human_review', $generated['final_decision'] ?? null);
        $this->assertSame('career_detail_ready_replacement_authority_import', $package['package_type'] ?? null);

        $this->assertSame(['software-developers'], $generated['source_context']['manual_hold_slugs'] ?? null);
        $this->assertTrue($generated['source_context']['manual_hold_in_existing_delta'] ?? false);

        $candidate = $generated['replacement_candidate'] ?? [];
        $this->assertSame('computer-occupations-all-other', $candidate['canonical_slug'] ?? null);
        $this->assertNotSame('software-developers', $candidate['canonical_slug'] ?? null);
        $this->assertContains('Not a manual-hold slug.', $candidate['why_selected'] ?? []);
        $this->assertContains('Not a CN proxy slug.', $candidate['why_selected'] ?? []);

        $counts = $generated['post_import_expected_counts'] ?? [];
        $this->assertSame(30, $counts['current_public_detail_count'] ?? null);
        $this->assertSame(1048, $counts['existing_union_detail_ready_count'] ?? null);
        $this->assertSame(1, $counts['replacement_added_to_union_after_import'] ?? null);
        $this->assertSame(1, $counts['manual_hold_excluded_from_clean_manifest'] ?? null);
        $this->assertSame(1048, $counts['clean_union_excluding_manual_hold'] ?? null);
        $this->assertSame(1018, $counts['clean_ready_not_public_delta_count'] ?? null);
        $this->assertSame(2036, $counts['expected_locale_rows_for_clean_delta'] ?? null);

        $this->assertTrue($generated['gates_before_next_manifest']['human_review_required'] ?? false);
        $this->assertTrue($generated['gates_before_next_manifest']['controlled_import_required'] ?? false);
        $this->assertTrue($generated['gates_before_next_manifest']['production_db_write_requires_future_explicit_approval'] ?? false);
        $this->assertTrue($generated['gates_before_next_manifest']['software_developers_must_remain_manual_hold'] ?? false);

        foreach (['sitemap_eligible_in_this_pr', 'llms_eligible_in_this_pr', 'footer_eligible_in_this_pr', 'search_channel_eligible_in_this_pr', 'runtime_public_in_this_pr'] as $field) {
            $this->assertFalse($generated['exposure_policy'][$field] ?? true, $field);
        }

        foreach (['no_cms_mutation', 'no_database_write', 'no_runtime_promotion', 'no_publish', 'no_deploy', 'no_search_channel_action', 'no_url_submission', 'no_external_search_api_call', 'no_frontend_fallback_authority', 'no_pseo_generation'] as $field) {
            $this->assertTrue($generated[$field] ?? false, $field);
        }

        $member = $package['replacement_member'] ?? [];
        $this->assertSame('computer-occupations-all-other', $member['canonical_slug'] ?? null);
        $this->assertFalse($member['manual_hold'] ?? true);
        $this->assertFalse($member['cn_proxy'] ?? true);
        $this->assertFalse($member['existing_union_member'] ?? true);

        $crosswalks = $member['proposed_crosswalk_imports'] ?? [];
        $this->assertCount(1, $crosswalks);
        $this->assertSame('us_soc', $crosswalks[0]['source_system'] ?? null);
        $this->assertSame('15-1299', $crosswalks[0]['source_code'] ?? null);

        $asset = $member['proposed_display_asset_import'] ?? [];
        $this->assertSame('career_job_public_display', $asset['asset_type'] ?? null);
        $this->assertSame('ready_for_pilot', $asset['status'] ?? null);
        $this->assertCount(24, $asset['component_order_json'] ?? []);
        $this->assertIsArray(data_get($asset, 'page_payload_json.page.en'));
        $this->assertIsArray(data_get($asset, 'page_payload_json.page.zh'));

        foreach (['sitemap_eligible', 'llms_eligible', 'footer_eligible', 'search_channel_eligible', 'runtime_public'] as $field) {
            $this->assertFalse($package['exposure_policy'][$field] ?? true, $field);
        }

        $this->assertSame('DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01', $generated['next_task'] ?? null);
    }
}
