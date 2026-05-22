<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelObservationGovernanceArchitectureContractTest extends TestCase
{
    #[Test]
    public function contract_file_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/observation-governance-architecture-contract.md'));
        $this->assertSame('observation-governance-architecture-contract.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-01', $this->artifact()['task'] ?? null);
        $this->assertSame('observation_governance_architecture_contract', $this->artifact()['purpose'] ?? null);
    }

    #[Test]
    public function required_event_types_and_states_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'published',
            'unpublished',
            'metadata_changed',
            'canonical_changed',
            'robots_changed',
            'locale_link_changed',
            'claim_boundary_changed',
            'runtime_verified',
            'search_channel_enqueued',
            'search_channel_submitted',
            'crawler_signal_observed',
            'digital_pr_signal_observed',
            'issue_detected',
            'issue_muted',
            'issue_reopened',
        ] as $eventType) {
            $this->assertContains($eventType, $artifact['required_event_types'] ?? []);
        }

        foreach ([
            'pending_runtime_check',
            'runtime_verified',
            'awaiting_search_engine_observation',
            'awaiting_crawler_observation',
            'needs_review',
            'muted',
            'closed',
        ] as $state) {
            $this->assertContains($state, $artifact['required_event_states'] ?? []);
        }
    }

    #[Test]
    public function authority_boundaries_forbid_truth_drift(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'crawler_logs_as_url_truth',
            'search_engine_responses_as_url_truth',
            'local_copy',
            'node2_local_db',
            'tencent_rds_business_tables',
            'raw_crawler_logs',
            'raw_request_payloads',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_sources'] ?? []);
        }

        foreach ([
            'create_url_truth',
            'mutate_url_truth',
            'create_search_channel_queue_entries',
            'approve_search_channel_queue_entries',
            'submit_urls',
            'mutate_cms_content',
            'read_raw_crawler_logs',
            'auto_fix_issues',
            'treat_search_engine_response_as_truth',
            'treat_crawler_hit_as_truth',
            'treat_digital_pr_referral_as_backlink_proof',
        ] as $behavior) {
            $this->assertContains($behavior, $artifact['forbidden_behaviors'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prove_this_pr_is_contract_only(): void
    {
        $flags = $this->artifact()['safety_flags'] ?? [];

        foreach ([
            'docs_only',
            'generated_json_only',
            'focused_tests_only',
        ] as $flag) {
            $this->assertTrue((bool) ($flags[$flag] ?? false), $flag.' must be true');
        }

        foreach ([
            'migration_added',
            'runtime_service_added',
            'production_mutation',
            'production_migration_execution',
            'collector_write',
            'scheduler_enabled',
            'search_submission',
            'external_search_api_call',
            'crawler_log_read',
            'cms_content_mutation',
            'metabase_exposure',
            'business_db_access',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($flags[$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_no_mutation_policy_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/observation-governance-architecture-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/observation-governance-architecture-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'observation queue is a future verification queue',
            'not url truth',
            'not search channel queue',
            'does not submit urls',
            'does not write cms',
            'does not read raw crawler logs',
            'does not auto-fix issues',
            'no-production-mutation policy',
            'digital pr signals are observation-only',
            'next task: `seo-obs-gov-02`',
            '"next_task": "seo-obs-gov-02"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/observation-governance-architecture-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
