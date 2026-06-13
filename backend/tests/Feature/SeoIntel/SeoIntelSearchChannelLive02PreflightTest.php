<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLive02PreflightTest extends TestCase
{
    #[Test]
    public function generated_artifact_allows_exact_human_approval_after_production_dry_run(): void
    {
        $artifactPath = base_path('docs/seo/generated/search-channel-live-02-preflight.v1.json');
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $approvalPhrase = 'I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item 1 channel indexnow URL https://fermatmind.com/en.';

        $this->assertSame('SEARCH-CHANNEL-LIVE-02-PREFLIGHT', $artifact['id'] ?? null);
        $this->assertSame('ready_for_human_approval_after_indexnow_config', $artifact['status'] ?? null);
        $this->assertFalse((bool) ($artifact['live_submission_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($artifact['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_activation'] ?? true));
        $this->assertSame($approvalPhrase, $artifact['approval_phrase'] ?? null);

        $this->assertSame(1, data_get($artifact, 'queue_item.id'));
        $this->assertSame('indexnow', data_get($artifact, 'queue_item.channel'));
        $this->assertSame('https://fermatmind.com/en', data_get($artifact, 'queue_item.canonical_url'));
        $this->assertSame('pending', data_get($artifact, 'queue_item.approval_state'));
        $this->assertSame('dry_run_ready', data_get($artifact, 'queue_item.execution_state'));
        $this->assertSame('fresh_production_dry_run_verified', data_get($artifact, 'queue_item.verification_state'));

        $this->assertSame('https://github.com/fermatmind/fap-api/pull/1529', data_get($artifact, 'executor.pr_url'));
        $this->assertSame('af8a050bfed88631cfc687775402548848ba87a1', data_get($artifact, 'executor.merge_commit'));
        $this->assertSame('success', data_get($artifact, 'executor.github_checks'));
        $this->assertSame('staging_only', data_get($artifact, 'executor.deployment_scope_after_merge'));
        $this->assertTrue((bool) data_get($artifact, 'executor.production_deploy_after_merge'));

        $this->assertSame('20260521220637', data_get($artifact, 'production_runtime.current_release'));
        $this->assertSame('35d1f33b038df4eac330475d072f25fbbfd66364', data_get($artifact, 'production_runtime.current_revision'));
        $this->assertTrue((bool) data_get($artifact, 'production_runtime.current_revision_contains_required_deploy_sha'));
        $this->assertTrue((bool) data_get($artifact, 'production_runtime.production_executor_presence_verified'));
        $this->assertTrue((bool) data_get($artifact, 'production_runtime.remote_read_only_command_verified'));
        $this->assertSame('success', data_get($artifact, 'production_runtime.local_ssh_attempt.result'));

        $this->assertNull($artifact['blocker'] ?? null);
        $this->assertSame([], data_get($artifact, 'required_rerun_commands', []));
        $this->assertSame('success', data_get($artifact, 'executor_dry_run.status'));
        $this->assertFalse((bool) data_get($artifact, 'executor_dry_run.result.external_calls_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'executor_dry_run.result.search_submission_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'executor_dry_run.result.writes_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'executor_dry_run.result.writes_committed', true));
        $this->assertSame('success', data_get($artifact, 'queue_dry_run.status'));
        $this->assertSame(1, data_get($artifact, 'queue_dry_run.result.planned_queue_count'));
        $this->assertFalse((bool) data_get($artifact, 'queue_dry_run.result.external_calls_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'queue_dry_run.result.writes_committed', true));
        $this->assertSame('ready', data_get($artifact, 'indexnow_live_configuration.status'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.configured_on_production'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.config_cache_rebuilt'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.live_submission_enabled'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.external_api_calls_enabled'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.indexnow_live_api_enabled'));
        $this->assertTrue((bool) data_get($artifact, 'indexnow_live_configuration.indexnow_key_present'));
        $this->assertSame(32, data_get($artifact, 'indexnow_live_configuration.indexnow_key_length'));
        $this->assertContains(data_get($artifact, 'indexnow_live_configuration.indexnow_key_location_host'), [
            'fermatmind.com',
            '<redacted-indexnow-key-location-host>',
        ]);
        $this->assertSame(32, data_get($artifact, 'indexnow_live_configuration.indexnow_key_location_public_bytes'));
        $this->assertSame('pass', data_get($artifact, 'live_gate_verification.status'));
        $this->assertSame(['approval_phrase_mismatch'], data_get($artifact, 'live_gate_verification.result.issues'));
        $this->assertFalse((bool) data_get($artifact, 'live_gate_verification.result.external_calls_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'live_gate_verification.result.search_submission_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'live_gate_verification.result.writes_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'live_gate_verification.result.writes_committed', true));
        $this->assertSame('SEARCH-CHANNEL-LIVE-02', data_get($artifact, 'next_allowed_action.canary_task_after_passing_preflight'));
        $this->assertSame($approvalPhrase, data_get($artifact, 'next_allowed_action.approval_phrase_required'));
        $this->assertSame('disable_indexnow_live_gates_and_rebuild_config_cache', data_get($artifact, 'next_allowed_action.post_attempt_required_action'));
    }
}
