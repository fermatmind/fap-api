<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogReadinessContractTest extends TestCase
{
    #[Test]
    public function artifact_requires_source_approval_before_any_log_read(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('crawler-log-readiness-contract.v1', $artifact['version'] ?? null);
        $this->assertContains('SEARCH-CHANNEL-QUEUE-00', $artifact['source_documents'] ?? []);

        foreach (['cdn_access_logs', 'nginx_access_logs', 'openresty_access_logs'] as $source) {
            $this->assertContains($source, $artifact['candidate_source_families'] ?? []);
        }

        foreach ([
            'owner',
            'path_or_export_mechanism',
            'retention',
            'masking_posture',
            'access_control',
            'rollback_owner',
        ] as $field) {
            $this->assertContains($field, $artifact['source_approval_required_fields'] ?? []);
        }
    }

    #[Test]
    public function storage_contract_is_aggregate_only_and_forbids_raw_fields(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'report_date',
            'bot_family',
            'source_engine',
            'route_family',
            'locale',
            'page_entity_type',
            'status_code_bucket',
            'response_time_bucket',
            'crawl_count',
            'private_flow_count',
            'noindex_count',
        ] as $field) {
            $this->assertContains($field, $artifact['allowed_stored_fields'] ?? []);
        }

        foreach ([
            'raw_ip',
            'cookie',
            'raw_cookie',
            'raw_user_agent',
            'full_raw_url_with_query_string',
            'raw_payload',
            'token',
            'api_key',
            'secret',
            'raw_email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_stored_fields'] ?? []);
            $this->assertNotContains($field, $artifact['allowed_stored_fields'] ?? []);
        }
    }

    #[Test]
    public function bot_classifier_includes_chinese_crawlers_without_search_actions(): void
    {
        $families = $this->artifact()['allowed_bot_families'] ?? [];

        foreach ([
            'googlebot',
            'bingbot',
            'baiduspider',
            'bytespider',
            'sogou',
            'so360',
            'shenma',
            'yandex',
            'other_bot',
            'unknown',
        ] as $family) {
            $this->assertContains($family, $families);
        }
    }

    #[Test]
    public function production_reads_config_changes_scheduler_and_raw_storage_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_log_read_in_this_pr',
            'cdn_config_changed_in_this_pr',
            'nginx_config_changed_in_this_pr',
            'openresty_config_changed_in_this_pr',
            'collector_write_executed_in_this_pr',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'env_edit_in_this_pr',
            'metabase_deployed_in_this_pr',
            'raw_user_agent_storage_allowed',
            'raw_ip_storage_allowed',
            'cookie_storage_allowed',
            'crawler_logs_as_url_truth_allowed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_production_log_read_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-readiness-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/crawler-log-readiness-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not read production crawler logs',
            'cdn access logs',
            'nginx access logs',
            'openresty access logs',
            'aggregate-only rows',
            'raw user agent strings may be used transiently',
            'must not trigger url submissions',
            'scheduler remains disabled',
            'next task: claim-lint-00',
            '"next_task": "claim-lint-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-readiness-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
