<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhCareerContentBatch05Test extends TestCase
{
    #[Test]
    public function career_content_package_defers_job_placeholders_and_preserves_claim_boundaries(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-career-content-batch-05.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-career-content-batch-05.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-career-content-batch-05.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CAREER-CONTENT-BATCH-05', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-career-content-batch-05.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('career_translation_group_readiness_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame($generated['total_items'] ?? null, count($items));
        $this->assertSame(36, $generated['career_guide_count'] ?? null);
        $this->assertSame(378, $generated['career_job_count'] ?? null);
        $this->assertSame(1, $generated['career_recommendation_count'] ?? null);
        $this->assertSame(378, $generated['job_deferred_translation_group_required_count'] ?? null);
        $this->assertTrue($generated['no_placeholder_job_pages'] ?? false);

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertFalse($item['pseo_eligible'] ?? true);
            $this->assertNotEmpty($item['recommendation_boundary_notes'] ?? '');
            $this->assertContains('hiring fit', $item['forbidden_claim_risks'] ?? []);
            $this->assertContains('career direction reference', $item['allowed_framing'] ?? []);
        }

        $jobItems = array_values(array_filter($items, fn (array $item): bool => ($item['career_asset_type'] ?? null) === 'career_job'));
        $this->assertCount(378, $jobItems);
        foreach ($jobItems as $jobItem) {
            $this->assertSame('deferred_translation_group_required', $jobItem['publish_state'] ?? null);
            $this->assertSame([], $jobItem['body_blocks_en_draft'] ?? null);
            $this->assertTrue($jobItem['no_placeholder_page'] ?? false);
            $this->assertNotEmpty($jobItem['job_code'] ?? '');
        }

        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['search_channel_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-RESULT-REPORT-ASSET-BATCH-06', $generated['next_task'] ?? null);
    }
}
