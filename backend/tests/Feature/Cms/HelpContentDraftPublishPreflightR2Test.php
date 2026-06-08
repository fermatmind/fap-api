<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use Tests\TestCase;

final class HelpContentDraftPublishPreflightR2Test extends TestCase
{
    public function test_help_content_draft_publish_preflight_r2_artifact_records_blocked_runtime_deploy_state(): void
    {
        $artifactPath = base_path('docs/help/generated/help-content-draft-publish-preflight-r2-01.v1.json');

        $this->assertFileExists($artifactPath);
        $this->assertFileExists(base_path('docs/operations/help-content-draft-publish-preflight-r2-2026-06-08.md'));

        $artifact = json_decode((string) file_get_contents($artifactPath), true);

        $this->assertIsArray($artifact);
        $this->assertSame('help-content-draft-publish-preflight-r2-01.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01', $artifact['task']['id'] ?? null);
        $this->assertSame(12, $artifact['cms_draft_evidence']['checked_rows'] ?? null);
        $this->assertSame(0, $artifact['cms_draft_evidence']['private_boundary_pattern_hits']['payment_id'] ?? null);
        $this->assertSame(12, $artifact['public_route_checks']['draft_routes_final_404'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_checks']['help_service_slug_hits'] ?? null);
        $this->assertFalse((bool) ($artifact['production_runtime_check']['contains_help_controlled_publish_runtime_merge'] ?? true));
        $this->assertSame('blocked', $artifact['decision']['status'] ?? null);
        $this->assertSame('NO_GO_FOR_PUBLISH_EXECUTE', $artifact['decision']['final_decision'] ?? null);
        $this->assertFalse((bool) ($artifact['decision']['publish_allowed'] ?? true));
        $this->assertSame('HELP-RUNTIME-PROD-DEPLOY-READINESS-01', $artifact['next_task_recommendation'] ?? null);
    }
}
