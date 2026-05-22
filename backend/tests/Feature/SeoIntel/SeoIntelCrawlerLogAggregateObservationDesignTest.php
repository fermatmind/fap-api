<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogAggregateObservationDesignTest extends TestCase
{
    #[Test]
    public function aggregate_observation_design_doc_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-aggregate-observation-design.md'));

        $artifact = $this->artifact();

        $this->assertSame('crawler-log-aggregate-observation-design.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-06', $artifact['task'] ?? null);
        $this->assertSame('aggregate_observation_design', $artifact['design_type'] ?? null);
        $this->assertSame('CRAWLER-LOG-07', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function design_keeps_current_pr_read_only_and_no_mutation(): void
    {
        $artifact = $this->artifact();

        foreach ([
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
    public function aggregate_grain_and_metrics_are_safe_dimensions_only(): void
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
            'http_status',
            'method_bucket',
            'query_present',
            'query_risk_state',
            'private_path_blocked',
            'source_log_family',
            'privacy_transform_version',
        ] as $field) {
            $this->assertContains($field, $artifact['aggregate_grain'] ?? []);
        }

        foreach ([
            'hit_count',
            'first_seen_at',
            'last_seen_at',
        ] as $measure) {
            $this->assertContains($measure, $artifact['aggregate_measures'] ?? []);
        }

        foreach ([
            'private_path_blocked_count',
            'unknown_public_path_count',
            'safe_public_canonical_path_count',
            'query_risk_state_breakdown',
            'bot_family_breakdown',
        ] as $metric) {
            $this->assertContains($metric, $artifact['safe_dashboard_metrics'] ?? []);
        }
    }

    #[Test]
    public function forbidden_inputs_and_persistent_fields_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'raw_production_access_logs',
            'raw_request_rows',
            'raw_ip_addresses',
            'raw_user_agents',
            'raw_request_uris',
            'raw_query_strings',
            'cookies',
            'headers',
            'tokens',
            'emails',
            'business_db_logs',
            'node2_local_db_or_logs',
            'tencent_rds_business_sources',
        ] as $input) {
            $this->assertContains($input, $artifact['forbidden_inputs'] ?? []);
        }

        foreach ([
            'ip_address',
            'remote_addr',
            'raw_user_agent',
            'raw_request_uri',
            'raw_query_string',
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
    }

    #[Test]
    public function url_truth_search_channel_and_issue_boundaries_are_locked(): void
    {
        $authority = $this->artifact()['authority_boundary'] ?? [];

        foreach ([
            'crawler_logs_are_url_truth',
            'crawler_logs_create_seo_urls',
            'crawler_logs_update_seo_urls',
            'crawler_logs_decide_canonical',
            'crawler_logs_decide_indexability',
            'crawler_logs_override_cms_backend_truth',
            'crawler_logs_create_search_channel_queue_items',
            'crawler_logs_approve_search_channel_queue_items',
            'crawler_logs_retry_search_channel_queue_items',
            'crawler_logs_submit_urls',
            'crawler_logs_auto_write_issue_queue',
        ] as $flag) {
            $this->assertFalse($authority[$flag] ?? true, $flag);
        }
    }

    #[Test]
    public function future_storage_contract_requires_seo_intel_and_separate_gates(): void
    {
        $storage = $this->artifact()['future_storage_requirements'] ?? [];

        $this->assertSame('seo_intel', $storage['connection'] ?? null);
        $this->assertSame('seo_crawler_logs_daily', $storage['preferred_table'] ?? null);
        $this->assertFalse($storage['raw_persistence_allowed'] ?? true);
        $this->assertTrue($storage['write_gate_disabled_by_default'] ?? false);
        $this->assertTrue($storage['dry_run_required_before_write'] ?? false);
        $this->assertTrue($storage['scheduler_requires_separate_readiness_task'] ?? false);
        $this->assertTrue($storage['idempotency_required'] ?? false);
    }

    #[Test]
    public function docs_explain_observation_design_is_not_url_eligibility_or_runtime_expansion(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-aggregate-observation-design.md')));

        foreach ([
            'does not expand production log reads',
            'not a url eligibility signal',
            'not be interpreted as evidence for url truth',
            'crawler log aggregates must not',
            'crawler-log-06 does not add a migration',
            'future aggregate storage must require',
            'crawler-log-07',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $doc);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-aggregate-observation-design.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
