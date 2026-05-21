<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLive02PreflightTest extends TestCase
{
    #[Test]
    public function generated_artifact_blocks_until_production_executor_is_deployed_and_verified(): void
    {
        $artifactPath = base_path('docs/seo/generated/search-channel-live-02-preflight.v1.json');
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('SEARCH-CHANNEL-LIVE-02-PREFLIGHT', $artifact['id'] ?? null);
        $this->assertSame('blocked_production_executor_not_deployed', $artifact['status'] ?? null);
        $this->assertFalse((bool) ($artifact['live_submission_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($artifact['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_activation'] ?? true));
        $this->assertNull($artifact['approval_phrase'] ?? null);

        $this->assertSame(1, data_get($artifact, 'queue_item.id'));
        $this->assertSame('indexnow', data_get($artifact, 'queue_item.channel'));
        $this->assertSame('https://fermatmind.com/en', data_get($artifact, 'queue_item.canonical_url'));
        $this->assertSame('pending', data_get($artifact, 'queue_item.approval_state'));
        $this->assertSame('dry_run_ready', data_get($artifact, 'queue_item.execution_state'));
        $this->assertSame('carried_forward_not_refreshed', data_get($artifact, 'queue_item.verification_state'));

        $this->assertSame('https://github.com/fermatmind/fap-api/pull/1529', data_get($artifact, 'executor.pr_url'));
        $this->assertSame('af8a050bfed88631cfc687775402548848ba87a1', data_get($artifact, 'executor.merge_commit'));
        $this->assertSame('success', data_get($artifact, 'executor.github_checks'));
        $this->assertSame('staging_only', data_get($artifact, 'executor.deployment_scope_after_merge'));

        $this->assertSame('effadd311f6c6bdfee137f8c9d10d9edb4ee23c4', data_get($artifact, 'production_runtime.last_successful_production_deploy_sha'));
        $this->assertFalse((bool) data_get($artifact, 'production_runtime.production_executor_presence_verified', true));
        $this->assertFalse((bool) data_get($artifact, 'production_runtime.remote_read_only_command_verified', true));
        $this->assertSame('failed_banner_timeout_after_tcp_22_open', data_get($artifact, 'production_runtime.local_ssh_attempt.result'));

        $this->assertSame('production_deploy_required', data_get($artifact, 'blocker.code'));
        $this->assertStringContainsString(
            'seo-intel:search-channel-submit --queue-item-id=1 --dry-run --json',
            implode("\n", data_get($artifact, 'required_rerun_commands', [])),
        );
        $this->assertSame('SEARCH-CHANNEL-LIVE-02-PREFLIGHT', data_get($artifact, 'next_allowed_action.task_after_backend_deploy'));
    }
}
