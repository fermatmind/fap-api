<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogAggregateStorageContractTest extends TestCase
{
    #[Test]
    public function storage_contract_doc_and_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-aggregate-storage-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('crawler-log-aggregate-storage-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-07', $artifact['task'] ?? null);
        $this->assertSame('aggregate_storage_contract', $artifact['contract_type'] ?? null);
        $this->assertSame('CRAWLER-LOG-08', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function contract_selects_scoped_v1_table_and_blocks_legacy_table_writes(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo_intel', $artifact['target_connection'] ?? null);
        $this->assertSame('seo_crawler_log_daily_aggregates', $artifact['target_table'] ?? null);
        $this->assertSame('seo_crawler_logs_daily', $artifact['legacy_table'] ?? null);
        $this->assertFalse($artifact['legacy_table_v1_write_approved'] ?? true);

        foreach ([
            'user_agent_hash',
            'path_display_masked',
            'metadata_json',
        ] as $reason) {
            $this->assertContains($reason, $artifact['legacy_table_block_reason'] ?? []);
        }
    }

    #[Test]
    public function current_pr_does_not_perform_mutating_or_live_operations(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'no_migration_in_this_pr',
            'no_production_log_read',
            'no_canary_execution',
            'no_database_write',
            'no_scheduler',
            'no_issue_queue_write',
            'no_url_truth_mutation',
            'no_search_channel_queue_enqueue',
            'no_search_submission',
            'no_external_search_api',
        ] as $flag) {
            $this->assertTrue($artifact[$flag] ?? false, $flag);
        }
    }

    #[Test]
    public function required_fields_and_idempotency_dimensions_are_safe_aggregate_only(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'log_date',
            'host',
            'surface_family',
            'bot_family',
            'bot_variant',
            'bot_verification_state',
            'route_family',
            'page_entity_type',
            'canonical_path',
            'path_hash',
            'http_status',
            'method_bucket',
            'query_present',
            'query_risk_state',
            'private_path_blocked',
            'hit_count',
            'first_seen_at',
            'last_seen_at',
            'source_log_family',
            'privacy_transform_version',
            'idempotency_key',
        ] as $field) {
            $this->assertContains($field, $artifact['required_fields'] ?? []);
        }

        foreach ([
            'log_date',
            'host',
            'surface_family',
            'bot_family',
            'route_family',
            'http_status',
            'query_risk_state',
            'privacy_transform_version',
        ] as $dimension) {
            $this->assertContains($dimension, $artifact['idempotency_dimensions'] ?? []);
        }
    }

    #[Test]
    public function write_gate_and_indexes_are_required(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED', $artifact['write_gate_env'] ?? null);
        $this->assertFalse($artifact['write_gate_enabled_by_default'] ?? true);
        $this->assertTrue($artifact['dry_run_required_before_write'] ?? false);

        foreach ([
            'unique_idempotency_key',
            'log_date_host_bot_family',
            'log_date_surface_family_route_family',
        ] as $index) {
            $this->assertContains($index, $artifact['required_indexes'] ?? []);
        }
    }

    #[Test]
    public function forbidden_fields_and_authority_boundaries_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'ip_address',
            'remote_addr',
            'raw_user_agent',
            'user_agent_hash',
            'raw_request_uri',
            'raw_query_string',
            'path_display_masked',
            'cookie',
            'headers',
            'authorization',
            'token',
            'api_key',
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'raw_payload',
            'raw_log_line',
            'event_payload',
            'metadata_json',
            'attributes_json',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_persistent_fields'] ?? []);
        }

        foreach (($artifact['authority_boundary'] ?? []) as $flag => $value) {
            $this->assertFalse($value, $flag);
        }
    }

    #[Test]
    public function docs_lock_storage_contract_without_implementing_storage(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-aggregate-storage-contract.md')));

        foreach ([
            'docs/generated/test only',
            'does not add a migration',
            'seo_crawler_log_daily_aggregates',
            'legacy table must not receive crawler-log v1 writes',
            'seo_intel',
            'seo_intel_crawler_log_aggregate_write_enabled',
            'crawler-log-08',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $doc);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-aggregate-storage-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
