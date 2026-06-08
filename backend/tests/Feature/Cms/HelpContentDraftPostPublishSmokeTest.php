<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use Tests\TestCase;

final class HelpContentDraftPostPublishSmokeTest extends TestCase
{
    public function test_help_content_draft_post_publish_smoke_records_runtime_blockers(): void
    {
        $backendPath = (string) getcwd();
        $artifactPath = $backendPath.'/docs/help/generated/help-content-draft-post-publish-smoke-01.v1.json';
        $reportPath = $backendPath.'/docs/operations/help-content-draft-post-publish-smoke-2026-06-08.md';

        $this->assertFileExists($artifactPath);
        $this->assertFileExists($reportPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);

        $this->assertIsArray($artifact);
        $this->assertSame('HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-01', $artifact['task_id'] ?? null);
        $this->assertSame('BLOCKED_RUNTIME_REPAIR_REQUIRED', $artifact['decision'] ?? null);
        $this->assertTrue((bool) ($artifact['publish_execution']['ok'] ?? false));
        $this->assertSame(12, $artifact['publish_execution']['target_count'] ?? null);
        $this->assertSame(0, $artifact['publish_execution']['would_create_count'] ?? null);
        $this->assertSame(0, $artifact['publish_execution']['blocked_count'] ?? null);
        $this->assertFalse((bool) ($artifact['publish_execution']['published_state']['is_indexable'] ?? true));
        $this->assertSame(12, $artifact['public_route_smoke']['http_200_count'] ?? null);
        $this->assertSame('blocked', $artifact['public_route_smoke']['robots_runtime_status'] ?? null);
        $this->assertSame('blocked', $artifact['public_route_smoke']['schema_runtime_status'] ?? null);
        $this->assertSame(0, $artifact['public_route_smoke']['private_pattern_hits'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_smoke']['help_service_slug_hits'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_smoke']['private_pattern_hits'] ?? null);
    }
}
