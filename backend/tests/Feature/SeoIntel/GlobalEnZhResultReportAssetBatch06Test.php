<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResultReportAssetBatch06Test extends TestCase
{
    #[Test]
    public function result_report_asset_package_requires_review_and_blocks_runtime_exposure(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-result-report-asset-batch-06.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-result-report-asset-batch-06.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-result-report-asset-batch-06.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-RESULT-REPORT-ASSET-BATCH-06', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-result-report-asset-batch-06.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('result_report_asset_draft_import_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertCount(23, $items);
        $this->assertSame(23, $generated['total_items'] ?? null);
        $this->assertSame(11, $generated['missing_en_counterparts'] ?? null);
        $this->assertSame(3, $generated['deferred_item_count'] ?? null);
        $this->assertSame(23, $generated['human_review_required_count'] ?? null);
        $this->assertSame(23, $generated['no_zh_fallback_required_count'] ?? null);

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertTrue($item['no_zh_fallback_required'] ?? false);
            $this->assertFalse($item['fallback_to_zh_allowed'] ?? true);
            $this->assertFalse($item['runtime_active'] ?? true);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertFalse($item['pseo_eligible'] ?? true);
            $this->assertFalse($item['cms_mutation_required_now'] ?? true);
            $this->assertFalse($item['import_allowed_without_human_review'] ?? true);
            $this->assertNotEmpty($item['draft_en_value'] ?? []);
            $this->assertContains('diagnosis', $item['forbidden_claims'] ?? []);
            $this->assertContains('hiring fit', $item['forbidden_claims'] ?? []);
        }

        $assetKeys = array_column($items, 'asset_key');
        $this->assertContains('mbti.backend_external_content_package_export', $assetKeys);
        $this->assertContains('riasec.140q_task_environment_role', $assetKeys);
        $this->assertContains('riasec.activity_task_examples', $assetKeys);
        $this->assertContains('riasec.aspirations_calibration', $assetKeys);
        $this->assertContains('riasec.dimension_deep_copy', $assetKeys);
        $this->assertContains('sds.result_report_assets', $assetKeys);
        $this->assertContains('clinical_combo.paid_report_block', $assetKeys);
        $this->assertContains('report_preview_paywall_checkout_email_capture', $assetKeys);

        $deferredKeys = $generated['deferred_asset_keys'] ?? [];
        $this->assertContains('mbti.backend_external_content_package_export', $deferredKeys);
        $this->assertContains('mbti.pdf.report_payload', $deferredKeys);
        $this->assertContains('mbti.email.result_report_summary', $deferredKeys);

        $riasec140 = array_values(array_filter($items, fn (array $item): bool => ($item['asset_key'] ?? null) === 'riasec.140q_task_environment_role'))[0];
        $this->assertSame(126, $riasec140['draft_en_value']['source_record_count'] ?? null);
        $this->assertSame(126, $riasec140['draft_en_value']['draft_record_count'] ?? null);

        $riasecActivity = array_values(array_filter($items, fn (array $item): bool => ($item['asset_key'] ?? null) === 'riasec.activity_task_examples'))[0];
        $this->assertSame(360, $riasecActivity['draft_en_value']['source_record_count'] ?? null);
        $this->assertSame(360, $riasecActivity['draft_en_value']['draft_record_count'] ?? null);

        $riasecAspirations = array_values(array_filter($items, fn (array $item): bool => ($item['asset_key'] ?? null) === 'riasec.aspirations_calibration'))[0];
        $this->assertSame(70, $riasecAspirations['draft_en_value']['source_record_count'] ?? null);
        $this->assertSame(70, $riasecAspirations['draft_en_value']['draft_record_count'] ?? null);

        $riasecDeep = array_values(array_filter($items, fn (array $item): bool => ($item['asset_key'] ?? null) === 'riasec.dimension_deep_copy'))[0];
        $this->assertSame(6, $riasecDeep['draft_en_value']['source_dimension_count'] ?? null);
        $this->assertSame(6, $riasecDeep['draft_en_value']['draft_dimension_count'] ?? null);

        $this->assertSame(0, $generated['runtime_active_count'] ?? null);
        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['search_channel_eligible_count'] ?? null);
        $this->assertSame(0, $generated['pseo_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-MEDIA-ALT-VISUAL-REVIEW-BATCH-07', $generated['next_task'] ?? null);
    }
}
