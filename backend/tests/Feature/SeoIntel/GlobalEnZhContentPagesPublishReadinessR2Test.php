<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class GlobalEnZhContentPagesPublishReadinessR2Test extends TestCase
{
    public function test_publish_readiness_r2_report_exists_with_closed_action_boundaries(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-publish-readiness-r2.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-publish-readiness-r2.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-PUBLISH-READINESS-R2', $generated['task'] ?? null);
        $this->assertSame(
            ['brand', 'charter', 'foundation', 'careers', 'policies'],
            $generated['target_pages'] ?? null,
        );

        $this->assertIsArray($generated['per_page_publish_readiness'] ?? null);
        $this->assertArrayHasKey('brand', $generated['per_page_publish_readiness']);
        $this->assertArrayHasKey('charter', $generated['per_page_publish_readiness']);
        $this->assertArrayHasKey('foundation', $generated['per_page_publish_readiness']);
        $this->assertArrayHasKey('careers', $generated['per_page_publish_readiness']);
        $this->assertArrayHasKey('policies', $generated['per_page_publish_readiness']);

        $this->assertSame('planned_public_benefit_shareholding', $generated['foundation_fact_state'] ?? null);
        $this->assertArrayHasKey('publish_scope_recommendation', $generated);
        $this->assertArrayHasKey('approval_phrase_for_future_publish', $generated);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_sitemap_llms_exposure'] ?? false);
        $this->assertTrue($generated['no_footer_nav_exposure'] ?? false);

        $this->assertArrayHasKey('final_decision', $generated);
        $this->assertArrayHasKey('next_task', $generated);
    }
}
