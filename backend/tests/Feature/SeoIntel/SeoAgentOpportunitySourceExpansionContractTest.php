<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentOpportunitySourceExpansionContractTest extends TestCase
{
    #[Test]
    public function generated_contract_declares_non_gsc_opportunity_source_families(): void
    {
        $artifact = $this->artifact();
        $sourceIds = array_column($artifact['source_families'] ?? [], 'id');

        foreach ([
            'gsc_performance',
            'cms_tdk_gap',
            'cms_faq_gap',
            'cms_internal_link_gap',
            'runtime_seo_qa',
            'sitemap_llms_gap',
            'hreflang_canonical_gap',
        ] as $sourceId) {
            $this->assertContains($sourceId, $sourceIds, $sourceId);
        }

        $this->assertFalse((bool) ($artifact['runtime_scanners_added'] ?? true));
        $this->assertSame('seo-agent-run-control-packet.v1', $artifact['run_packet_required_before_downstream_action'] ?? null);
        $this->assertContains('cms_tdk_gap_readonly_scanner', $artifact['implementation_sequence'] ?? []);
    }

    #[Test]
    public function candidate_shape_is_sanitized_and_review_only_by_default(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'source_family',
            'source_id',
            'subject_type',
            'subject_ref',
            'safe_path',
            'severity',
            'evidence_refs',
            'recommended_next_step',
            'allowed_action',
            'blocked_actions',
        ] as $field) {
            $this->assertContains($field, data_get($artifact, 'candidate_shape.required_fields', []), $field);
        }

        foreach (['p0', 'p1', 'p2', 'p3'] as $severity) {
            $this->assertContains($severity, data_get($artifact, 'candidate_shape.severity_values', []), $severity);
        }

        foreach ([
            'readonly_review',
            'gpt_review_handoff',
            'cms_draft_package_dry_run',
        ] as $allowedAction) {
            $this->assertContains($allowedAction, data_get($artifact, 'candidate_shape.allowed_actions_without_separate_approval', []), $allowedAction);
        }

        foreach ([
            'raw_url',
            'raw_query',
            'credential_path',
            'service_account_json',
            'client_email',
            'private_key',
            'token',
            'cookie',
            'session',
            'raw_payload',
            'cms_draft_body',
        ] as $forbiddenField) {
            $this->assertContains($forbiddenField, $artifact['forbidden_output_fields'] ?? [], $forbiddenField);
        }
    }

    #[Test]
    public function contract_keeps_all_execution_surfaces_disabled(): void
    {
        $artifact = $this->artifact();

        foreach ($artifact['source_families'] ?? [] as $source) {
            $this->assertFalse((bool) ($source['write_allowed'] ?? true), (string) ($source['id'] ?? 'unknown'));
        }

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'sitemap_submission',
            'scheduler_activation',
            'queue_worker_started',
            'production_env_change',
            'source_code_mutation',
            'pr_train_metadata_change',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-agent-opportunity-source-expansion.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('seo-agent-opportunity-source-expansion.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-AGENT-OPPORTUNITY-SOURCE-EXPANSION-01', $artifact['task'] ?? null);

        return $artifact;
    }
}
