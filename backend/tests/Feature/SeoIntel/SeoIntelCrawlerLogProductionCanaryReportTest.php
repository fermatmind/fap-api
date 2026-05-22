<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogProductionCanaryReportTest extends TestCase
{
    #[Test]
    public function production_canary_report_doc_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-production-canary-report.md'));

        $artifact = $this->artifact();

        $this->assertSame('crawler-log-production-canary-report.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-05', $artifact['task'] ?? null);
        $this->assertSame('production_canary_read_only_summary', $artifact['report_type'] ?? null);
        $this->assertSame('CRAWLER-LOG-06', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function report_records_the_successful_canary_summary_without_raw_log_details(): void
    {
        $artifact = $this->artifact();
        $canary = $artifact['successful_canary'] ?? [];

        $this->assertSame('success', $canary['status'] ?? null);
        $this->assertSame('single_source_production_canary_dry_run', $canary['mode'] ?? null);
        $this->assertTrue($canary['dry_run'] ?? false);
        $this->assertTrue($canary['no_write'] ?? false);
        $this->assertFalse($canary['writes_attempted'] ?? true);
        $this->assertFalse($canary['writes_committed'] ?? true);
        $this->assertFalse($canary['external_calls_attempted'] ?? true);
        $this->assertFalse($canary['search_submission_attempted'] ?? true);
        $this->assertFalse($canary['scheduler_enabled'] ?? true);
        $this->assertFalse($canary['collector_write_attempted'] ?? true);
        $this->assertFalse($canary['raw_persistence'] ?? true);

        $this->assertSame(1000, $canary['parsed_line_count'] ?? null);
        $this->assertSame(1000, $canary['sanitized_row_count'] ?? null);
        $this->assertSame(154, $canary['aggregate_row_count'] ?? null);
        $this->assertSame(956, $canary['blocked_private_path_count'] ?? null);
        $this->assertSame(1, $canary['unknown_bot_count'] ?? null);
        $this->assertSame(0, $canary['safe_public_canonical_path_count'] ?? null);
    }

    #[Test]
    public function blocked_attempt_is_recorded_as_fail_closed_without_reading_or_writing(): void
    {
        $blocked = $this->artifact()['blocked_attempt'] ?? [];

        $this->assertSame('blocked', $blocked['status'] ?? null);
        $this->assertSame('source_path_not_readable', $blocked['reason'] ?? null);
        $this->assertFalse($blocked['production_log_read_attempted'] ?? true);
        $this->assertSame(0, $blocked['source_line_count_read'] ?? null);
        $this->assertFalse($blocked['writes_attempted'] ?? true);
        $this->assertFalse($blocked['writes_committed'] ?? true);
    }

    #[Test]
    public function aggregate_breakdowns_are_recorded_without_raw_rows(): void
    {
        $artifact = $this->artifact();
        $breakdowns = $artifact['breakdowns'] ?? [];

        $this->assertSame(999, $breakdowns['bot_family']['non_bot'] ?? null);
        $this->assertSame(1, $breakdowns['bot_family']['unknown_bot'] ?? null);
        $this->assertSame(956, $breakdowns['route_family']['blocked_private_path'] ?? null);
        $this->assertSame(6, $breakdowns['route_family']['static_asset'] ?? null);
        $this->assertSame(38, $breakdowns['route_family']['unknown_public_path'] ?? null);
        $this->assertSame(786, $breakdowns['http_status']['200'] ?? null);
        $this->assertSame(127, $breakdowns['http_status']['401'] ?? null);
        $this->assertSame(67, $breakdowns['http_status']['404'] ?? null);
        $this->assertSame(824, $breakdowns['query_risk_state']['unknown_query_present'] ?? null);

        $this->assertArrayNotHasKey('aggregate_rows', $artifact);
        $this->assertArrayNotHasKey('raw_rows', $artifact);
        $this->assertArrayNotHasKey('path_hashes', $artifact);
    }

    #[Test]
    public function privacy_and_authority_boundaries_remain_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['privacy_boundary']['aggregate_only'] ?? false);
        $this->assertTrue($artifact['privacy_boundary']['no_raw_persistence'] ?? false);

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
            $this->assertContains($field, $artifact['privacy_boundary']['forbidden_report_fields'] ?? []);
        }

        foreach ([
            'crawler_logs_are_url_truth',
            'crawler_logs_create_seo_urls',
            'crawler_logs_decide_canonical',
            'crawler_logs_decide_indexability',
            'crawler_logs_override_cms_backend_truth',
            'crawler_logs_enqueue_search_channel',
            'crawler_logs_auto_write_issue_queue',
            'crawler_logs_submit_urls',
        ] as $flag) {
            $this->assertFalse($artifact['authority_boundary'][$flag] ?? true, $flag);
        }
    }

    #[Test]
    public function follow_up_observation_contract_does_not_expand_read_scope_or_add_mutation_paths(): void
    {
        $contract = $this->artifact()['follow_up_observation_contract'] ?? [];

        foreach ([
            'do_not_expand_reading_scope',
            'single_approved_source_until_separate_approval',
            'bounded_max_lines_required',
            'dry_run_no_write_until_separate_storage_approval',
            'aggregate_only',
            'no_scheduler',
            'no_issue_queue_auto_write',
            'no_url_truth_mutation',
            'no_search_channel_queue_enqueue',
            'no_search_submission',
            'no_metabase_exposure',
        ] as $flag) {
            $this->assertTrue($contract[$flag] ?? false, $flag);
        }
    }

    #[Test]
    public function docs_state_the_report_is_observation_only(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-production-canary-report.md')));

        foreach ([
            'read-only observation artifact',
            'does not expand crawler log collection',
            'no raw persistence',
            'crawler logs remain aggregate observability only',
            'not a url eligibility signal',
            'not to widen production log access',
            'no search submission',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $doc);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-production-canary-report.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
