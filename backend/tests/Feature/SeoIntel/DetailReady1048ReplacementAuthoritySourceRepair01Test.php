<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthoritySourceRepair01Test extends TestCase
{
    public function test_source_repair_report_and_package_define_safe_replacement_source(): void
    {
        $reportPath = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-source-repair-01.v1.json');
        $packagePath = base_path('docs/seo/import-packages/detail-ready-1048-replacement-authority-source-repair-01.import.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($packagePath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1048_replacement_authority_source_repair.v1', $report['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01', $report['task'] ?? null);
        $this->assertSame('source_repair_completed_ready_for_controlled_import', $report['final_decision'] ?? null);

        $source = $report['selected_replacement_source'] ?? [];
        $this->assertSame('digital-forensics-analysts', $source['canonical_slug'] ?? null);
        $this->assertSame('15-1299.06', $source['source_code'] ?? null);
        $this->assertSame('15-1299', $source['us_soc_code'] ?? null);
        $this->assertSame('repo_backed_source_repair_package_only', $source['candidate_status'] ?? null);

        $requirements = $report['source_repair_requirements'] ?? [];
        foreach ([
            'must_be_outside_current_1048_union_in_target_authority',
            'must_be_non_indexable_before_controlled_import',
            'must_not_be_manual_hold',
            'must_not_be_blocked',
            'must_not_be_cn_proxy',
            'must_have_onet_soc_2019_crosswalk',
            'must_have_us_soc_crosswalk',
            'must_have_display_asset_v4_2',
            'must_not_expose_sitemap_llms_footer_in_this_pr',
        ] as $field) {
            $this->assertTrue($requirements[$field] ?? false, $field);
        }

        $plannedWrites = $report['planned_controlled_import_writes_after_future_approval'] ?? [];
        $this->assertSame(0, $plannedWrites['index_states'] ?? null);
        $this->assertSame(0, $plannedWrites['runtime_promotions'] ?? null);
        $this->assertSame(0, $plannedWrites['sitemap_llms_footer_exposure'] ?? null);

        foreach (['sitemap_eligible_in_this_pr', 'llms_eligible_in_this_pr', 'footer_eligible_in_this_pr', 'search_channel_eligible_in_this_pr', 'runtime_public_in_this_pr'] as $field) {
            $this->assertFalse($report['exposure_policy'][$field] ?? true, $field);
        }

        foreach (['no_cms_mutation', 'no_database_write', 'no_runtime_promotion', 'no_publish', 'no_deploy', 'no_search_channel_action', 'no_url_submission', 'no_external_search_api_call', 'no_frontend_fallback_authority', 'no_pseo_generation'] as $field) {
            $this->assertTrue($report[$field] ?? false, $field);
        }

        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01', $report['next_task'] ?? null);

        $this->assertSame('career_detail_ready_replacement_authority_source_repair', $package['package_type'] ?? null);
        $packageSource = $package['replacement_source'] ?? [];
        $this->assertSame('digital-forensics-analysts', $packageSource['canonical_slug'] ?? null);
        $this->assertFalse($packageSource['manual_hold'] ?? true);
        $this->assertFalse($packageSource['cn_proxy'] ?? true);
        $this->assertFalse($packageSource['known_blocked_slug'] ?? true);

        $crosswalks = $packageSource['proposed_crosswalk_imports'] ?? [];
        $this->assertCount(2, $crosswalks);
        $this->assertSame('onet_soc_2019', $crosswalks[0]['source_system'] ?? null);
        $this->assertSame('15-1299.06', $crosswalks[0]['source_code'] ?? null);
        $this->assertSame('us_soc', $crosswalks[1]['source_system'] ?? null);
        $this->assertSame('15-1299', $crosswalks[1]['source_code'] ?? null);

        $asset = $packageSource['proposed_display_asset_import'] ?? [];
        $this->assertSame('digital-forensics-analysts', $asset['canonical_slug'] ?? null);
        $this->assertSame('display.surface.v1', $asset['surface_version'] ?? null);
        $this->assertSame('v4.2', $asset['asset_version'] ?? null);
        $this->assertSame('ready_for_pilot', $asset['status'] ?? null);
        $this->assertCount(24, $asset['component_order_json'] ?? []);
        $this->assertIsArray($asset['page_payload_json']['page']['en'] ?? null);
        $this->assertIsArray($asset['page_payload_json']['page']['zh'] ?? null);

        foreach (['sitemap_eligible', 'llms_eligible', 'footer_eligible', 'search_channel_eligible', 'runtime_public'] as $field) {
            $this->assertFalse($package['exposure_policy'][$field] ?? true, $field);
        }

        $encoded = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('software-developers","manual_hold":false', $encoded);
        $this->assertStringNotContainsString('precise career recommendation', $asset['page_payload_json']['page']['en']['hero']['boundary'] ?? '');
    }
}
