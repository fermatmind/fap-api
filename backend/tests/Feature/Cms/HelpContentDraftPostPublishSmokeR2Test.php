<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use Tests\TestCase;

final class HelpContentDraftPostPublishSmokeR2Test extends TestCase
{
    public function test_help_content_draft_post_publish_smoke_r2_records_noindex_pass_and_schema_blocker(): void
    {
        $backendPath = (string) getcwd();
        $artifactPath = $backendPath.'/docs/help/generated/help-content-draft-post-publish-smoke-r2-01.v1.json';
        $reportPath = $backendPath.'/docs/operations/help-content-draft-post-publish-smoke-r2-2026-06-08.md';

        $this->assertFileExists($artifactPath);
        $this->assertFileExists($reportPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);

        $this->assertIsArray($artifact);
        $this->assertSame('HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-R2-01', $artifact['task']['id'] ?? null);
        $this->assertSame('PARTIAL_GO_SCHEMA_RUNTIME_BLOCKED', $artifact['decision']['status'] ?? null);
        $this->assertSame('pass', $artifact['decision']['robots_runtime_status'] ?? null);
        $this->assertSame('blocked', $artifact['decision']['schema_runtime_status'] ?? null);
        $this->assertSame(12, $artifact['public_route_smoke']['checked_pages'] ?? null);
        $this->assertSame(12, $artifact['public_route_smoke']['http_200_count'] ?? null);
        $this->assertSame(12, $artifact['public_route_smoke']['robots_noindex_count'] ?? null);
        $this->assertSame(0, $artifact['public_route_smoke']['robots_index_follow_count'] ?? null);
        $this->assertSame(0, $artifact['public_route_smoke']['faqpage_total_hits'] ?? null);
        $this->assertSame(0, $artifact['public_route_smoke']['private_tokenized_pattern_hits'] ?? null);
        $this->assertSame([], $artifact['public_route_smoke']['canonical_count_failures'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_smoke']['help_service_slug_hits'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_smoke']['private_tokenized_pattern_hits'] ?? null);
        $this->assertFalse((bool) ($artifact['scope_validation']['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['scope_validation']['publish_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['scope_validation']['deploy_performed'] ?? true));
        $this->assertSame('HELP-SERVICE-FAQ-SCHEMA-RUNTIME-R2-01', $artifact['decision']['next_task_recommendation'] ?? null);
    }
}
