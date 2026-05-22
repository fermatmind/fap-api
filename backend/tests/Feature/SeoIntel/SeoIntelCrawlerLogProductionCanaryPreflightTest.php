<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogProductionCanaryPreflightTest extends TestCase
{
    #[Test]
    public function preflight_doc_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-production-canary-preflight.md'));
        $this->assertSame('crawler-log-production-canary-preflight.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-04', $this->artifact()['task'] ?? null);
        $this->assertSame('CRAWLER-LOG-04-CANARY', $this->artifact()['next_task'] ?? null);
    }

    #[Test]
    public function preflight_blocks_production_reads_writes_scheduler_submission_and_mutations(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'no_production_log_read',
            'no_canary_execution',
            'no_raw_persistence',
            'no_database_writes',
            'no_collector_write',
            'no_scheduler',
            'no_migration',
            'no_deploy',
            'no_env_edit',
            'no_external_search_api_call',
            'no_search_submission',
            'no_metabase_exposure',
            'no_metabase_mutation',
            'no_business_db_access',
            'no_url_truth_creation',
            'no_search_channel_queue_creation',
            'no_issue_queue_auto_write',
        ] as $flag) {
            $this->assertTrue($artifact[$flag] ?? false, $flag);
        }
    }

    #[Test]
    public function source_approval_and_canary_limits_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'I explicitly approve CRAWLER-LOG-04 production canary for source <log_path> with max_lines=1000 and no raw persistence.',
            $artifact['production_canary_approval_phrase'] ?? null,
        );
        $this->assertTrue($artifact['production_canary_requires_human_approval'] ?? false);
        $this->assertSame(1000, $artifact['canary_limits']['max_lines_lte'] ?? null);

        foreach ([
            'single_source_only',
            'short_time_window_only',
            'no_raw_persistence',
            'no_scheduler',
            'no_issue_queue_write',
            'no_search_channel_queue_write',
            'no_url_truth_write',
            'no_search_submission',
            'no_external_search_api_call',
            'no_metabase_mutation',
            'no_business_db_access',
            'no_tencent_rds_access',
            'no_node2_access',
        ] as $limit) {
            $this->assertTrue($artifact['canary_limits'][$limit] ?? false, $limit);
        }

        foreach ([
            'source_log_family',
            'log_path',
            'log_format',
            'owner',
            'retention_policy',
            'approved_execution_environment',
            'query_string_presence',
            'cookie_header_presence',
            'private_route_presence',
            'max_line_count',
            'time_window',
            'privacy_classification',
            'rollback_abort_owner',
        ] as $requiredField) {
            $this->assertContains($requiredField, $artifact['source_approval_required_fields'] ?? []);
        }
    }

    #[Test]
    public function forbidden_sources_and_persistent_fields_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'Node2 local Laravel log',
            'Node2 local DB',
            'business DB log',
            'payment log',
            'provider webhook log',
            'application debug log',
            'raw request payload log',
            'unapproved production raw access log',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_sources'] ?? []);
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
    public function current_crawler_log_command_allows_only_fixture_or_single_source_canary_modes_without_live_execution_flags(): void
    {
        Artisan::call('seo-intel:crawler-log-observe --help');
        $help = Artisan::output();

        foreach ([
            '--production',
            '--tail',
            '--schedule',
            '--write',
            '--submit',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }

        foreach ([
            '--fixture',
            '--source',
            '--approval-phrase',
            '--dry-run',
            '--no-write',
            '--json',
            '--limit',
        ] as $allowedOption) {
            $this->assertStringContainsString($allowedOption, $help);
        }
    }

    #[Test]
    public function docs_lock_canary_boundary_and_no_go_conditions(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-production-canary-preflight.md')));
        $artifactJson = strtolower((string) json_encode($this->artifact(), JSON_THROW_ON_ERROR));

        foreach ([
            'not a production log read',
            'not a canary execution',
            'single-source canary mode',
            'max_lines <= 1000',
            'no raw persistence',
            'no scheduler',
            'no issue queue write',
            'no search submission',
            'no metabase mutation',
            'crawler logs remain aggregate observability only',
            '"crawler_logs_are_url_truth":false',
            'crawler-log-04-canary',
        ] as $required) {
            $this->assertStringContainsString($required, $doc."\n".$artifactJson);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-production-canary-preflight.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
