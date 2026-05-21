<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogReadinessScanTest extends TestCase
{
    #[Test]
    public function readiness_scan_doc_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-readiness-scan.md'));

        $artifact = $this->artifact();

        $this->assertSame('crawler-log-readiness-scan.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-01', $artifact['task'] ?? null);
        $this->assertSame('ready_for_crawler_log_fixture_parser_mvp', $artifact['final_decision'] ?? null);
        $this->assertSame('CRAWLER-LOG-02', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function scan_locks_no_mutation_and_no_production_access_flags(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'no_production_log_read',
            'no_runtime_parser_added',
            'no_scheduler',
            'no_collector_write',
            'no_production_migration',
            'no_deploy',
            'no_env_edit',
            'no_external_search_api_call',
            'no_search_submission',
            'no_metabase_exposure',
            'no_business_db_access',
        ] as $flag) {
            $this->assertTrue((bool) ($artifact[$flag] ?? false), $flag.' must be true');
        }
    }

    #[Test]
    public function source_approval_is_required_before_any_production_log_read(): void
    {
        $source = $this->artifact()['source_approval_readiness'] ?? [];

        $this->assertFalse((bool) ($source['approved_production_source_exists'] ?? true));

        foreach ([
            'nginx_openresty_access_log',
            'cdn_edge_access_log',
            'alb_slb_access_log',
        ] as $allowedSource) {
            $this->assertContains($allowedSource, $source['allowed_future_source_families'] ?? []);
        }

        foreach ([
            'node2_local_laravel_log',
            'node2_local_db',
            'business_db_log',
            'payment_log',
            'provider_webhook_log',
            'application_debug_log',
            'raw_request_payload_log',
            'unapproved_production_raw_access_log',
        ] as $forbiddenSource) {
            $this->assertContains($forbiddenSource, $source['forbidden_sources'] ?? []);
        }
    }

    #[Test]
    public function schema_gap_is_recorded_before_any_persistent_write(): void
    {
        $schema = $this->artifact()['schema_readiness'] ?? [];

        $this->assertSame('seo_intel', $schema['target_connection'] ?? null);
        $this->assertSame('seo_crawler_logs_daily', $schema['existing_table'] ?? null);
        $this->assertTrue((bool) ($schema['migration_has_seo_intel_connection'] ?? false));
        $this->assertSame('existing_table_is_aggregate_but_not_v1_contract_shape', $schema['schema_gap'] ?? null);
        $this->assertFalse((bool) ($schema['persistent_write_ready'] ?? true));
        $this->assertTrue((bool) ($schema['fixture_no_write_ready'] ?? false));

        foreach (['user_agent_hash', 'path_display_masked', 'metadata_json'] as $field) {
            $this->assertContains($field, $schema['fields_requiring_v1_reconciliation_before_persistent_writes'] ?? []);
        }
    }

    #[Test]
    public function parser_and_collector_gaps_are_scoped_to_fixture_parser_mvp(): void
    {
        $readiness = $this->artifact()['parser_collector_readiness'] ?? [];

        $this->assertTrue((bool) ($readiness['foundation_collectors_exist'] ?? false));
        $this->assertTrue((bool) ($readiness['foundation_collectors_fixture_only'] ?? false));
        $this->assertFalse((bool) ($readiness['production_log_read_attempted'] ?? true));

        foreach ([
            'host_surface_family_normalization',
            'bot_variant',
            'bot_verification_state',
            'route_family_map',
            'query_present',
            'query_risk_state',
            'complete_private_path_denylist',
            'v1_bot_family_names',
            'unknown_public_path_hash_only_semantics',
        ] as $gap) {
            $this->assertContains($gap, $readiness['current_gaps_for_v1'] ?? []);
        }
    }

    #[Test]
    public function config_and_scheduler_safety_remain_blocked(): void
    {
        $config = $this->artifact()['config_safety'] ?? [];
        $scheduler = $this->artifact()['scheduler_scan'] ?? [];

        foreach ([
            'allow_production_log_read',
            'chinese_crawler_live_log_read_enabled',
            'allow_external_api_calls',
            'allow_production_crawl',
            'collectors_enabled_default',
        ] as $flag) {
            $this->assertFalse((bool) ($config[$flag] ?? true), $flag.' must remain false');
        }

        $this->assertTrue((bool) ($config['dry_run_default'] ?? false));

        foreach ([
            'crawler_log_scheduler_entry_found',
            'seo_intel_collect_scheduled',
            'crawler_log_foundation_scheduled',
            'chinese_crawler_log_foundation_scheduled',
        ] as $flag) {
            $this->assertFalse((bool) ($scheduler[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function privacy_and_url_truth_boundaries_are_locked(): void
    {
        $artifact = $this->artifact();
        $privacy = $artifact['privacy_boundary'] ?? [];
        $truth = $artifact['url_truth_boundary'] ?? [];

        foreach ([
            'ip_address',
            'remote_addr',
            'raw_user_agent',
            'raw_request_uri',
            'raw_query_string',
            'cookie',
            'headers',
            'token',
            'api_key',
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'raw_payload',
            'raw_log_line',
            'metadata_json',
        ] as $field) {
            $this->assertContains($field, $privacy['forbidden_persistent_fields'] ?? []);
        }

        $this->assertFalse((bool) ($privacy['raw_persistence_allowed'] ?? true));

        foreach ([
            'crawler_logs_are_url_truth',
            'crawler_logs_create_seo_urls',
            'crawler_logs_decide_canonical',
            'crawler_logs_decide_indexability',
            'crawler_logs_enqueue_search_channel',
            'crawler_logs_auto_write_issue_queue',
            'frontend_fallback_authority_allowed',
            'static_sitemap_fallback_authority_allowed',
            'static_llms_fallback_authority_allowed',
            'node2_local_db_authority_allowed',
            'external_search_source_authority_allowed',
        ] as $flag) {
            $this->assertFalse((bool) ($truth[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_record_final_decision_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-readiness-scan.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/crawler-log-readiness-scan.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ready_for_crawler_log_fixture_parser_mvp',
            'crawler-log-02',
            'existing table does not contain the full v1 field set',
            'no production log read',
            'fixture-only/no-write',
            'crawler logs remain observation data only',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-readiness-scan.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
