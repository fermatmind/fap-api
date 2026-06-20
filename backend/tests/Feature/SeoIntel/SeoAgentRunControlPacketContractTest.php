<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentRunControlPacketContractTest extends TestCase
{
    #[Test]
    public function generated_contract_defines_run_packet_fields_and_approval_states(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-agent-run-control-packet.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('seo-agent-run-control-packet.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-AGENT-RUN-CONTROL-PACKET-02', $artifact['task'] ?? null);
        $this->assertFalse((bool) ($artifact['runtime_execution_enabled'] ?? true));

        foreach ([
            'run_id',
            'run_mode',
            'trigger',
            'scope',
            'input_refs',
            'evidence_refs',
            'model_review',
            'approval',
            'forbidden_actions',
            'allowed_actions',
            'output_artifacts',
            'negative_guarantees',
            'next_step',
        ] as $field) {
            $this->assertContains($field, $artifact['required_fields'] ?? [], $field);
        }

        $this->assertContains('readonly_discovery', $artifact['allowed_run_modes'] ?? []);
        $this->assertContains('gpt_review_handoff', $artifact['allowed_run_modes'] ?? []);
        $this->assertContains('cms_draft_dry_run', $artifact['allowed_run_modes'] ?? []);
        $this->assertSame('not_requested', $artifact['default_approval_state'] ?? null);
        $this->assertContains('approved_for_single_canary_write', $artifact['allowed_approval_states'] ?? []);
    }

    #[Test]
    public function contract_keeps_gpt_review_and_execution_boundaries_separate(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-agent-run-control-packet.v1.json')),
            true
        );

        $this->assertSame('gpt_5_5_pro', data_get($artifact, 'model_review.reviewer'));
        $this->assertSame('review_only', data_get($artifact, 'model_review.role'));
        $this->assertFalse((bool) data_get($artifact, 'model_review.execution_permission', true));

        foreach ([
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'sitemap_submission',
            'scheduler_activation',
            'queue_worker_activation',
            'production_env_update',
            'source_code_mutation',
            'bulk_import',
        ] as $action) {
            $this->assertContains($action, $artifact['forbidden_actions_by_default'] ?? [], $action);
        }

        foreach ([
            'raw_query',
            'raw_url',
            'credential_path',
            'service_account_json',
            'client_email',
            'private_key',
            'token',
            'cookie',
            'session',
        ] as $forbiddenContent) {
            $this->assertContains($forbiddenContent, data_get($artifact, 'artifact_requirements.forbidden_content', []), $forbiddenContent);
        }

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'queue_worker_started',
            'production_env_change',
            'source_code_mutation',
            'pr_train_metadata_change',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }
}
