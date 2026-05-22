<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelObservationQueueSchemaContractTest extends TestCase
{
    #[Test]
    public function schema_contract_artifact_exists_and_names_future_table(): void
    {
        $this->assertFileExists(base_path('docs/seo/observation-queue-schema-contract.md'));
        $this->assertSame('observation-queue-schema-contract.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-02', $this->artifact()['task'] ?? null);
        $this->assertSame('seo_observation_queue', $this->artifact()['table_name'] ?? null);
    }

    #[Test]
    public function proposed_fields_match_contract_without_raw_payload_columns(): void
    {
        $artifact = $this->artifact();
        $fields = $artifact['proposed_fields'] ?? [];

        foreach ([
            'id',
            'event_uid',
            'event_type',
            'event_state',
            'source_system',
            'source_event_id',
            'canonical_url_hash',
            'canonical_url',
            'locale',
            'page_entity_type',
            'entity_id_or_slug',
            'entity_key',
            'entity_source',
            'observation_target',
            'runtime_check_state',
            'search_observation_state',
            'crawler_observation_state',
            'claim_boundary_state',
            'dedupe_key',
            'priority',
            'scheduled_for',
            'observed_at',
            'closed_at',
            'safe_context_hash',
            'created_at',
            'updated_at',
        ] as $field) {
            $this->assertContains($field, $fields);
        }

        foreach ($artifact['forbidden_fields'] ?? [] as $forbiddenField) {
            $this->assertNotContains($forbiddenField, $fields);
        }
    }

    #[Test]
    public function idempotency_and_dedupe_are_deterministic_and_safe(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('event_uid', $artifact['idempotency_strategy']['unique_key'] ?? null);
        $this->assertFalse((bool) ($artifact['idempotency_strategy']['raw_inputs_allowed'] ?? true));
        $this->assertSame('dedupe_key', $artifact['dedupe_key_strategy']['field'] ?? null);
        $this->assertTrue((bool) ($artifact['dedupe_key_strategy']['deterministic'] ?? false));
        $this->assertFalse((bool) ($artifact['dedupe_key_strategy']['history_deleting'] ?? true));
        $this->assertFalse((bool) ($artifact['dedupe_key_strategy']['raw_inputs_allowed'] ?? true));

        foreach (['source_system', 'event_type', 'canonical_url_hash', 'entity_key', 'observation_target'] as $input) {
            $this->assertContains($input, $artifact['idempotency_strategy']['deterministic_inputs'] ?? []);
        }
    }

    #[Test]
    public function authority_sources_and_forbidden_behaviors_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'cms_backend',
            'runtime_verifier',
            'url_truth',
            'search_channel_queue',
            'crawler_aggregate_observation',
            'issue_queue',
            'claim_boundary_checker',
            'digital_pr_manual_observation',
            'ops_seo_read_model',
        ] as $source) {
            $this->assertContains($source, $artifact['allowed_source_systems'] ?? []);
        }

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'crawler_log_as_url_truth',
            'search_engine_response_as_url_truth',
            'local_copy',
            'node2_local_db',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_sources'] ?? []);
        }

        foreach ([
            'submit_urls',
            'create_search_channel_queue_rows',
            'mutate_search_channel_queue_rows',
            'write_cms_records',
            'read_raw_crawler_logs',
            'store_raw_json_blobs',
            'auto_fix_issue_queue',
            'act_as_url_truth',
        ] as $behavior) {
            $this->assertContains($behavior, $artifact['forbidden_behaviors'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prove_no_schema_implementation_or_writes(): void
    {
        $flags = $this->artifact()['safety_flags'] ?? [];

        foreach (['docs_only', 'generated_json_only', 'focused_tests_only'] as $flag) {
            $this->assertTrue((bool) ($flags[$flag] ?? false), $flag.' must be true');
        }

        foreach ([
            'migration_added',
            'migration_executed',
            'runtime_writer_added',
            'seo_intel_write',
            'scheduler_enabled',
            'search_submission',
            'cms_mutation',
            'crawler_log_read',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($flags[$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_no_migration_no_write_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/observation-queue-schema-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/observation-queue-schema-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'future `seo_observation_queue` table contract',
            'does not add a real migration',
            'does not run a migration',
            'does not write `seo_intel`',
            'does not create a runtime writer',
            'no raw payload fields',
            'no raw json blobs',
            'no raw crawler-log fields',
            'no search submission behavior',
            'no cms mutation behavior',
            'next task: `seo-obs-gov-03`',
            '"next_task": "seo-obs-gov-03"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/observation-queue-schema-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
