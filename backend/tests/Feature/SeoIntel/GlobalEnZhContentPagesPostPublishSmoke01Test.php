<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class GlobalEnZhContentPagesPostPublishSmoke01Test extends TestCase
{
    public function test_post_publish_smoke_report_exists_with_closed_boundaries(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-post-publish-smoke-01.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-post-publish-smoke-01.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-POST-PUBLISH-SMOKE-01', $generated['task'] ?? null);
        $this->assertSame(
            ['brand', 'charter', 'foundation', 'careers', 'policies'],
            $generated['target_pages'] ?? null,
        );

        $this->assertArrayHasKey('cms_record_state', $generated);
        $this->assertArrayHasKey('public_frontend_runtime_check', $generated);
        $this->assertArrayHasKey('suspected_blocker', $generated);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_external_search_api_call'] ?? false);

        $this->assertArrayHasKey('final_decision', $generated);
        $this->assertArrayHasKey('recommended_next_task', $generated);
    }
}
