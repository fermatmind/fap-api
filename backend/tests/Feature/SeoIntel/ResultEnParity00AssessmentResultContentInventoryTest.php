<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResultEnParity00AssessmentResultContentInventoryTest extends TestCase
{
    #[Test]
    public function generated_inventory_artifact_lands_expected_read_only_baseline(): void
    {
        $jsonPath = base_path('docs/seo/generated/result-en-parity-00-assessment-result-content-inventory.v1.json');
        $markdownPath = base_path('docs/seo/result-en-parity-00-assessment-result-content-inventory.md');

        $this->assertFileExists($jsonPath);
        $this->assertFileExists($markdownPath);

        $payload = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('result-en-parity-00-assessment-result-content-inventory.v1', $payload['schema_version'] ?? null);
        $this->assertSame('RESULT-EN-PARITY-00', $payload['task'] ?? null);
        $this->assertSame(
            'result_en_parity_inventory_completed_ready_for_asset_catalog_fix',
            $payload['final_decision'] ?? null
        );

        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_commit_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_user_data_accessed'] ?? true));

        $this->assertFalse((bool) ($payload['authority_rule']['frontend_fallback_authority'] ?? true));
        $this->assertSame(
            'backend scoring, CMS, and result/report asset catalogs',
            $payload['authority_rule']['result_asset_authority'] ?? null
        );

        $families = $payload['test_families'] ?? [];
        $this->assertCount(8, $families);

        $byCode = [];
        foreach ($families as $family) {
            $this->assertIsArray($family['result_routes'] ?? null);
            $this->assertIsArray($family['backend_scoring_source'] ?? null);
            $this->assertIsArray($family['backend_result_serializer'] ?? null);
            $this->assertIsArray($family['frontend_renderer'] ?? null);
            $this->assertIsArray($family['report_section_keys'] ?? null);
            $this->assertIsInt($family['zh_asset_count'] ?? null);
            $this->assertIsInt($family['en_asset_count'] ?? null);
            $this->assertIsArray($family['missing_en_asset_keys'] ?? null);
            $this->assertIsArray($family['pdf_email_share_coverage'] ?? null);
            $this->assertArrayHasKey('my_results_card_coverage', $family);
            $this->assertArrayHasKey('claim_boundary_coverage', $family);
            $this->assertIsArray($family['locale_behavior_tests'] ?? null);
            $this->assertArrayHasKey('result_content_architecture', $family);

            $byCode[$family['test_code']] = $family;
        }

        foreach ([
            'MBTI',
            'BIG5_OCEAN',
            'ENNEAGRAM',
            'EQ_60',
            'RIASEC',
            'SDS_20',
            'CLINICAL_COMBO_68',
            'IQ_INTELLIGENCE_QUOTIENT',
        ] as $requiredCode) {
            $this->assertArrayHasKey($requiredCode, $byCode);
        }

        $this->assertSame('high', $byCode['MBTI']['chinese_leakage_risk'] ?? null);
        $this->assertContains(
            'backend_external_content_package_export_required',
            $byCode['MBTI']['missing_en_asset_keys'] ?? []
        );

        $this->assertSame(436, $byCode['BIG5_OCEAN']['zh_asset_count'] ?? null);
        $this->assertSame(436, $byCode['BIG5_OCEAN']['en_asset_count'] ?? null);
        $this->assertContains(
            'result_page_v2.route_matrix.en',
            $byCode['BIG5_OCEAN']['missing_en_asset_keys'] ?? []
        );

        $this->assertSame(19, $byCode['RIASEC']['zh_asset_count'] ?? null);
        $this->assertSame(0, $byCode['RIASEC']['en_asset_count'] ?? null);
        $this->assertContains(
            'content_assets/riasec/*.en.json',
            $byCode['RIASEC']['missing_en_asset_keys'] ?? []
        );

        $this->assertSame(46, $byCode['CLINICAL_COMBO_68']['zh_asset_count'] ?? null);
        $this->assertSame(38, $byCode['CLINICAL_COMBO_68']['en_asset_count'] ?? null);
        $this->assertContains(
            'clinical_combo_68.paid_action_anxiety_14d.en',
            $byCode['CLINICAL_COMBO_68']['missing_en_asset_keys'] ?? []
        );

        $this->assertSame(3, $byCode['IQ_INTELLIGENCE_QUOTIENT']['zh_asset_count'] ?? null);
        $this->assertSame(0, $byCode['IQ_INTELLIGENCE_QUOTIENT']['en_asset_count'] ?? null);
        $this->assertContains(
            'iq.dimensions.visual_spatial_insight.en',
            $byCode['IQ_INTELLIGENCE_QUOTIENT']['missing_en_asset_keys'] ?? []
        );

        $findingIds = array_column($payload['architecture_findings'] ?? [], 'id');
        $this->assertContains('backend_authority_mixed_by_family', $findingIds);
        $this->assertContains('zh_fallback_can_silently_pass', $findingIds);
        $this->assertContains('frontend_interpretation_copy_authority_risk', $findingIds);

        $nextPrIds = array_column($payload['recommended_next_prs'] ?? [], 'id');
        $this->assertContains('RESULT-EN-PARITY-01', $nextPrIds);
        $this->assertContains('RESULT-EN-PARITY-02', $nextPrIds);
        $this->assertContains('RESULT-EN-PARITY-03', $nextPrIds);

        $markdown = (string) file_get_contents($markdownPath);
        $this->assertStringContainsString(
            'Decision output: `result_en_parity_inventory_completed_ready_for_asset_catalog_fix`',
            $markdown
        );
        $this->assertStringContainsString('does not translate content, modify scoring, mutate CMS, deploy', $markdown);
        $this->assertStringContainsString('no runtime ownership', $markdown);
    }
}
