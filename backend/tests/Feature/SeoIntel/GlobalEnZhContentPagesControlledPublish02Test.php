<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class GlobalEnZhContentPagesControlledPublish02Test extends TestCase
{
    public function test_controlled_publish_02_report_exists_with_closed_boundaries(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-controlled-publish-02.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-controlled-publish-02.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-02', $generated['task'] ?? null);
        $this->assertSame(
            ['brand', 'charter', 'foundation', 'careers', 'policies'],
            $generated['target_pages'] ?? null,
        );

        $this->assertSame('planned_public_benefit_shareholding', $generated['foundation_fact_state'] ?? null);
        $this->assertTrue($generated['approval_phrase_verified'] ?? false);
        $this->assertTrue($generated['command_verified'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_external_search_api_call'] ?? false);
        $this->assertTrue($generated['no_out_of_scope_cms_write'] ?? false);

        $this->assertArrayHasKey('final_decision', $generated);
        $this->assertArrayHasKey('next_task', $generated);
    }
}
