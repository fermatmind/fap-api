<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class ResultEnParity05MbtiContentExportTest extends TestCase
{
    public function test_mbti_backend_asset_key_export_exists(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('result-en-parity-05-mbti-content-export.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('RESULT-EN-PARITY-05', $artifact['pr_id'] ?? null);
        $this->assertSame('MBTI', $artifact['family'] ?? null);
        $this->assertSame(
            'mbti_backend_content_package_export_manifest_ready_frontend_clone_deauthorized',
            $artifact['decision'] ?? null
        );

        $this->assertTrue((bool) ($artifact['authority']['backend_scoring_cms_result_asset_catalog_is_authority'] ?? false));
        $this->assertFalse((bool) ($artifact['authority']['frontend_fallback_is_authority'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['frontend_interpretation_copy_authority_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['fap_web_modified'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['scoring_change'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['production_mutation'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['cms_mutation'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['deploy'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['search_channel_action'] ?? true));

        $sources = array_column($artifact['backend_authority_sources'] ?? [], 'path');

        $this->assertContains('backend/app/Services/Assessment/Drivers/MbtiDriver.php', $sources);
        $this->assertContains('backend/app/Services/Legacy/Mbti/Content/LegacyMbtiPackRepository.php', $sources);
        $this->assertContains('backend/app/Services/Legacy/Mbti/Report/LegacyMbtiReportAssetRepository.php', $sources);
        $this->assertContains('backend/app/Services/Mbti/Adapters/MbtiReportAuthoritySourceAdapter.php', $sources);
        $this->assertContains('backend/app/Services/Mbti/Adapters/MbtiPersonalityProfileAuthoritySourceAdapter.php', $sources);
    }

    public function test_canonical_report_section_keys_are_exported_without_using_frontend_authority(): void
    {
        $artifact = $this->artifact();
        $sectionExport = $artifact['canonical_section_export'] ?? [];

        $this->assertSame('backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php', $sectionExport['registry_path'] ?? null);
        $this->assertSame(33, $sectionExport['section_key_count'] ?? null);

        foreach ([
            'letters_intro',
            'overview',
            'trait_overview',
            'career.summary',
            'growth.summary',
            'relationships.summary',
            'growth.motivators',
            'relationships.rel_risks',
        ] as $sectionKey) {
            $this->assertContains($sectionKey, $sectionExport['section_keys'] ?? []);
        }

        $assetKeys = array_column($artifact['asset_key_inventory'] ?? [], 'key');

        $this->assertContains('mbti.report.canonical_section_registry', $assetKeys);
        $this->assertContains('mbti.report.external_content_package', $assetKeys);
        $this->assertContains('mbti.report.legacy_generated_fallback_copy', $assetKeys);
    }

    public function test_frontend_clone_content_is_classified_non_authoritative_migration_only(): void
    {
        $artifact = $this->artifact();
        $classification = $artifact['frontend_clone_classification'] ?? [];

        $this->assertSame('fap-web', $classification['repo'] ?? null);
        $this->assertFalse((bool) ($classification['modified_in_this_pr'] ?? true));
        $this->assertSame('non_authoritative_migration_only', $classification['authority_status'] ?? null);
        $this->assertSame(16, $classification['base_content_file_count'] ?? null);
        $this->assertSame(32, $classification['variant_patch_file_count'] ?? null);
        $this->assertSame(48, $classification['total_clone_content_file_count'] ?? null);
        $this->assertContains(
            'components/result/mbti/clone/content/index.ts',
            $classification['reference_paths'] ?? []
        );

        $assetByKey = $this->assetByKey($artifact);

        foreach ([
            'mbti.frontend_clone_content_base',
            'mbti.frontend_clone_content_variants',
        ] as $key) {
            $this->assertSame('non_authoritative_migration_only', $assetByKey[$key]['authority_status'] ?? null);
            $this->assertTrue((bool) ($assetByKey[$key]['missing_en'] ?? false));
            $this->assertTrue((bool) ($assetByKey[$key]['interpretation_copy'] ?? false));
            $this->assertTrue((bool) ($assetByKey[$key]['fail_closed_for_en'] ?? false));
        }
    }

    public function test_missing_english_interpretation_keys_are_explicit_and_fail_closed(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'backend_external_content_package_export_required',
            'legacy_mbti_generated_fallback_copy.en',
            'frontend_mbti_clone_content_base.en',
            'frontend_mbti_clone_content_variants.en',
            'mbti.share.public_projection_summary.en',
            'mbti.pdf.report_payload.en',
            'mbti.email.result_report_summary.en',
            'mbti.my_results.card_summary.en',
        ] as $key) {
            $this->assertContains($key, $artifact['missing_en_asset_keys'] ?? []);
        }

        foreach ($artifact['asset_key_inventory'] ?? [] as $asset) {
            if (! (bool) ($asset['interpretation_copy'] ?? false)) {
                continue;
            }

            if (! (bool) ($asset['missing_en'] ?? false)) {
                continue;
            }

            $this->assertTrue(
                (bool) ($asset['fail_closed_for_en'] ?? false),
                sprintf('%s must fail closed when English interpretation copy is missing.', $asset['key'] ?? 'unknown')
            );
        }

        $this->assertSame(
            'fail_closed_missing_authoritative_en_no_frontend_clone_fallback',
            $artifact['architecture_finding']['english_runtime_policy'] ?? null
        );
    }

    public function test_claim_boundary_and_deferred_assets_remain_review_gated(): void
    {
        $artifact = $this->artifact();
        $boundary = $artifact['claim_boundary'] ?? [];

        $this->assertSame('exploratory_workstyle_signal_only', $boundary['career_language'] ?? null);

        foreach ([
            'precise career recommendation',
            'best career for you',
            'hiring suitability',
            'job fit guarantee',
            'career success prediction',
            'salary prediction',
            'turnover prediction',
        ] as $claim) {
            $this->assertContains($claim, $boundary['forbidden_claims'] ?? []);
        }

        $this->assertNotEmpty($artifact['deferred_assets'] ?? []);
        $this->assertNotEmpty($artifact['next_pr_recommendations'] ?? []);
        $this->assertFalse((bool) ($artifact['architecture_finding']['mass_content_generation'] ?? true));
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/result-en-parity-05-mbti-content-export.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, mixed>>
     */
    private function assetByKey(array $artifact): array
    {
        $assets = [];
        foreach ($artifact['asset_key_inventory'] ?? [] as $asset) {
            if (! is_array($asset) || ! is_string($asset['key'] ?? null)) {
                continue;
            }

            $assets[$asset['key']] = $asset;
        }

        return $assets;
    }
}
