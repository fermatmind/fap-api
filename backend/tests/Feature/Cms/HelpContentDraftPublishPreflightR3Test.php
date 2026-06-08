<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use Tests\TestCase;

final class HelpContentDraftPublishPreflightR3Test extends TestCase
{
    public function test_help_content_draft_publish_preflight_r3_artifact_records_ready_publish_preflight(): void
    {
        $backendPath = (string) getcwd();
        $artifactPath = $backendPath.'/docs/help/generated/help-content-draft-publish-preflight-r3-01.v1.json';

        $this->assertFileExists($artifactPath);
        $this->assertFileExists($backendPath.'/docs/operations/help-content-draft-publish-preflight-r3-2026-06-08.md');

        $artifact = json_decode((string) file_get_contents($artifactPath), true);

        $this->assertIsArray($artifact);
        $this->assertSame('help-content-draft-publish-preflight-r3-01.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R3-01', $artifact['task']['id'] ?? null);
        $this->assertSame('ready_for_exact_publish_authorization', $artifact['decision']['status'] ?? null);
        $this->assertSame('GO_FOR_EXACT_PUBLISH_AUTHORIZATION_PROMPT', $artifact['decision']['final_decision'] ?? null);
        $this->assertFalse((bool) ($artifact['decision']['publish_allowed_in_this_pr'] ?? true));
        $this->assertTrue((bool) ($artifact['decision']['publish_authorization_prompt_allowed'] ?? false));
        $this->assertTrue((bool) ($artifact['decision']['ready_for_exact_publish_authorization'] ?? false));
        $this->assertSame([], $artifact['decision']['blocking_reasons'] ?? null);

        $this->assertSame(12, $artifact['cms_draft_evidence']['checked_rows'] ?? null);
        $this->assertSame(0, $artifact['cms_draft_evidence']['private_boundary_pattern_hits']['payment_id'] ?? null);
        $this->assertSame(0, $artifact['cms_draft_evidence']['private_boundary_pattern_hits']['transaction_id'] ?? null);

        $backendRuntime = $artifact['production_runtime_checks']['backend'] ?? [];
        $this->assertSame('bf937f67543dc9df656219e195e364d37c8bb63a', $backendRuntime['active_backend_revision_sha'] ?? null);
        $this->assertTrue((bool) ($backendRuntime['content_pages_publish_controlled_visible'] ?? false));
        $this->assertTrue((bool) ($backendRuntime['help_service_scope_visible'] ?? false));
        $this->assertTrue((bool) ($backendRuntime['dry_run_ok'] ?? false));
        $this->assertTrue((bool) ($backendRuntime['dry_run'] ?? false));
        $this->assertFalse((bool) ($backendRuntime['execute'] ?? true));
        $this->assertFalse((bool) ($backendRuntime['writes_committed'] ?? true));
        $this->assertSame(12, $backendRuntime['target_count'] ?? null);
        $this->assertSame(12, $backendRuntime['would_publish_count'] ?? null);
        $this->assertSame(0, $backendRuntime['would_create_count'] ?? null);
        $this->assertSame(0, $backendRuntime['blocked_count'] ?? null);
        $this->assertSame(['zh-CN', 'en'], $backendRuntime['target_locales'] ?? null);
        $this->assertSame([false], $backendRuntime['after_indexable'] ?? null);
        $this->assertFalse((bool) ($backendRuntime['search_channel_action_attempted'] ?? true));
        $this->assertFalse((bool) ($backendRuntime['url_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($backendRuntime['deploy_attempted'] ?? true));
        $this->assertTrue((bool) ($backendRuntime['no_record_creation'] ?? false));
        $this->assertTrue((bool) ($backendRuntime['no_upsert_missing'] ?? false));
        $this->assertTrue((bool) ($backendRuntime['no_out_of_scope_cms_write'] ?? false));

        $frontendRuntime = $artifact['production_runtime_checks']['frontend'] ?? [];
        $this->assertSame('37299875412c68a39e2a096f1f797800b96ff92e', $frontendRuntime['active_frontend_sha'] ?? null);
        $this->assertSame(4, $frontendRuntime['pm2_process_count'] ?? null);
        $this->assertSame(['online'], $frontendRuntime['pm2_statuses'] ?? null);

        $this->assertSame(12, $artifact['public_route_checks']['draft_routes_final_404'] ?? null);
        $this->assertSame(12, $artifact['public_route_checks']['draft_routes_noindex'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_checks']['help_service_slug_hits'] ?? null);
        $this->assertSame(0, $artifact['sitemap_llms_checks']['private_pattern_hits'] ?? null);

        $scope = $artifact['scope_validation'] ?? [];
        $this->assertFalse((bool) ($scope['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($scope['publish_performed'] ?? true));
        $this->assertFalse((bool) ($scope['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($scope['search_submission_performed'] ?? true));
        $this->assertFalse((bool) ($scope['private_url_access_performed'] ?? true));
        $this->assertFalse((bool) ($scope['secret_env_cookie_token_read'] ?? true));
        $this->assertFalse((bool) ($scope['payment_or_refund_performed'] ?? true));
        $this->assertFalse((bool) ($scope['payment_provider_changed'] ?? true));
        $this->assertFalse((bool) ($scope['operator_approval_claimed'] ?? true));
    }
}
