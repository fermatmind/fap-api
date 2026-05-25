<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResultEnParity01AssetCatalogGateTest extends TestCase
{
    #[Test]
    public function asset_catalog_exports_all_covered_families_and_fail_closed_issues(): void
    {
        $export = $this->artifact();

        $this->assertSame('result-en-parity-01-asset-catalog-gate.v1', $export['schema_version'] ?? null);
        $this->assertSame('RESULT-EN-PARITY-01', $export['task'] ?? null);
        $this->assertFalse((bool) ($export['gate']['production_mutation'] ?? true));
        $this->assertFalse((bool) ($export['gate']['cms_mutation'] ?? true));
        $this->assertFalse((bool) ($export['gate']['search_channel_action'] ?? true));
        $this->assertFalse((bool) ($export['gate']['fap_web_authority'] ?? true));

        $families = $export['families'] ?? [];
        $this->assertSame([
            'MBTI',
            'BIG5_OCEAN',
            'ENNEAGRAM',
            'EQ_60',
            'RIASEC',
            'SDS_20',
            'CLINICAL_COMBO_68',
            'IQ_INTELLIGENCE_QUOTIENT',
        ], array_keys($families));

        foreach ($families as $family => $config) {
            $this->assertNotEmpty($config['authority_sources'] ?? [], $family);
            $this->assertNotEmpty($config['sensitive_claim_boundary'] ?? null, $family);
            $this->assertNotEmpty($config['assets'] ?? [], $family);

            foreach ($config['assets'] as $asset) {
                $this->assertArrayHasKey('key', $asset);
                $this->assertArrayHasKey('has_zh', $asset);
                $this->assertArrayHasKey('has_en', $asset);
                $this->assertArrayHasKey('missing_en', $asset);
                $this->assertArrayHasKey('fallback_to_zh_detected', $asset);
                $this->assertArrayHasKey('presentation_label_only', $asset);
                $this->assertArrayHasKey('interpretation_copy', $asset);
                $this->assertArrayHasKey('sensitive_claim_boundary', $asset);
                $this->assertArrayHasKey('fail_closed_for_en', $asset);
            }
        }

        $this->assertSame(8, $export['summary']['family_count'] ?? null);
        $this->assertGreaterThan(30, $export['summary']['asset_count'] ?? 0);
        $this->assertGreaterThan(0, $export['summary']['presentation_label_only_count'] ?? 0);
        $this->assertGreaterThan(0, $export['summary']['interpretation_copy_count'] ?? 0);
        $this->assertGreaterThan(0, $export['summary']['sensitive_claim_boundary_count'] ?? 0);
        $this->assertGreaterThan(0, $export['summary']['fail_closed_count'] ?? 0);

        $blockingKeys = array_column($export['blocking_issues'] ?? [], 'asset_key');

        $this->assertContains('mbti.external_content_package_export', $blockingKeys);
        $this->assertContains('big5.result_page_v2.route_matrix', $blockingKeys);
        $this->assertContains('enneagram.type_registry', $blockingKeys);
        $this->assertContains('riasec.lifecycle_copy.share_pdf_history', $blockingKeys);
        $this->assertContains('clinical_combo_68.paid_action_anxiety_14d', $blockingKeys);
        $this->assertContains('iq.dimensions.visual_spatial_insight', $blockingKeys);
    }

    #[Test]
    public function english_interpretation_copy_cannot_silently_fallback_to_zh_cn(): void
    {
        $export = $this->artifact();

        foreach ($export['families'] as $family => $config) {
            foreach ($config['assets'] as $asset) {
                if (! (bool) ($asset['interpretation_copy'] ?? false)) {
                    continue;
                }

                if ((bool) ($asset['missing_en'] ?? false)) {
                    $this->assertTrue(
                        (bool) ($asset['fail_closed_for_en'] ?? false),
                        sprintf('%s:%s must fail closed when EN interpretation copy is missing.', $family, $asset['key'])
                    );
                }
            }
        }

        $blocking = $export['blocking_issues'] ?? [];

        $this->assertNotEmpty($blocking);
        foreach ($blocking as $issue) {
            $this->assertSame('missing_english_interpretation_asset_must_not_render_zh_cn', $issue['reason'] ?? null);
            $this->assertNotEmpty($issue['family'] ?? null);
            $this->assertNotEmpty($issue['asset_key'] ?? null);
        }
    }

    #[Test]
    public function generated_json_records_non_runtime_gate_summary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(8, $artifact['summary']['family_count'] ?? null);
        $this->assertSame(47, $artifact['summary']['asset_count'] ?? null);
        $this->assertSame(32, $artifact['summary']['missing_en_count'] ?? null);
        $this->assertSame(29, $artifact['summary']['fallback_to_zh_detected_count'] ?? null);
        $this->assertSame(8, $artifact['summary']['presentation_label_only_count'] ?? null);
        $this->assertSame(39, $artifact['summary']['interpretation_copy_count'] ?? null);
        $this->assertSame(26, $artifact['summary']['sensitive_claim_boundary_count'] ?? null);
        $this->assertSame(32, $artifact['summary']['fail_closed_count'] ?? null);
        $this->assertFalse((bool) ($artifact['gate']['fap_web_authority'] ?? true));
        $this->assertSame(
            'fail_closed_for_missing_english_interpretation_assets',
            $artifact['gate']['mode'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/result-en-parity-01-asset-catalog-gate.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
