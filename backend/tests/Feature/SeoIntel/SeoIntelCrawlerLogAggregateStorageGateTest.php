<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogAggregateStorageWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogAggregateStorageGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.crawler_log_aggregate_storage.write_enabled' => false,
        ]);

        DB::purge('seo_intel');
        $this->createAggregateTable();
    }

    #[Test]
    public function migration_is_scoped_to_seo_intel_and_omits_forbidden_columns(): void
    {
        $migration = (string) file_get_contents(base_path('database/migrations/seo_intel/2026_05_22_111800_create_seo_crawler_log_daily_aggregates_table.php'));

        $this->assertStringContainsString('protected $connection = \'seo_intel\';', $migration);
        $this->assertStringContainsString('seo_crawler_log_daily_aggregates', $migration);

        foreach ([
            'ip_address',
            'remote_addr',
            'raw_user_agent',
            'user_agent_hash',
            'raw_request_uri',
            'raw_query_string',
            'path_display_masked',
            'metadata_json',
            'event_payload',
            'attributes_json',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $migration);
        }
    }

    #[Test]
    public function dry_run_no_write_does_not_persist_rows(): void
    {
        $output = (new CrawlerLogAggregateStorageWriter)->write($this->aggregateRows(), dryRun: true, noWrite: true);

        $this->assertSame('crawler_log_aggregate_storage', $output['runtime'] ?? null);
        $this->assertSame('seo_crawler_log_daily_aggregates', $output['target_table'] ?? null);
        $this->assertTrue($output['dry_run'] ?? false);
        $this->assertTrue($output['no_write'] ?? false);
        $this->assertFalse($output['writes_attempted'] ?? true);
        $this->assertFalse($output['writes_committed'] ?? true);
        $this->assertFalse($output['external_calls_attempted'] ?? true);
        $this->assertFalse($output['search_submission_attempted'] ?? true);
        $this->assertFalse($output['production_log_read_attempted'] ?? true);
        $this->assertFalse($output['scheduler_enabled'] ?? true);
        $this->assertFalse($output['collector_write_attempted'] ?? true);
        $this->assertFalse($output['raw_persistence'] ?? true);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_crawler_log_daily_aggregates')->count());
    }

    #[Test]
    public function write_attempt_is_blocked_without_explicit_gate(): void
    {
        $output = (new CrawlerLogAggregateStorageWriter)->write($this->aggregateRows(), dryRun: false, noWrite: false);

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertTrue($output['writes_attempted'] ?? false);
        $this->assertFalse($output['writes_committed'] ?? true);
        $this->assertContains('write_gate_disabled', $output['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_crawler_log_daily_aggregates')->count());
    }

    #[Test]
    public function enabled_write_targets_only_aggregate_table_and_is_idempotent(): void
    {
        config(['seo_intel.crawler_log_aggregate_storage.write_enabled' => true]);

        $writer = new CrawlerLogAggregateStorageWriter;
        $first = $writer->write($this->aggregateRows(), dryRun: false, noWrite: false);
        $second = $writer->write($this->aggregateRows(), dryRun: false, noWrite: false);

        $this->assertSame('success', $first['status'] ?? null);
        $this->assertTrue($first['writes_attempted'] ?? false);
        $this->assertTrue($first['writes_committed'] ?? false);
        $this->assertSame(1, $first['written_rows'] ?? null);
        $this->assertSame('success', $second['status'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_crawler_log_daily_aggregates')->count());

        $row = (array) DB::connection('seo_intel')->table('seo_crawler_log_daily_aggregates')->first();
        $this->assertSame('/en/research/safe-report', $row['canonical_path'] ?? null);
        $this->assertArrayNotHasKey('raw_user_agent', $row);
        $this->assertArrayNotHasKey('metadata_json', $row);
    }

    #[Test]
    public function docs_and_artifact_lock_storage_gate_boundary(): void
    {
        $artifactPath = base_path('docs/seo/generated/crawler-log-aggregate-storage-gate.v1.json');
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertSame('crawler-log-aggregate-storage-gate.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-08', $artifact['task'] ?? null);
        $this->assertSame('seo_crawler_log_daily_aggregates', $artifact['target_table'] ?? null);
        $this->assertSame('SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED', $artifact['write_gate_env'] ?? null);
        $this->assertFalse($artifact['write_gate_enabled_by_default'] ?? true);
        $this->assertTrue($artifact['no_production_migration_run'] ?? false);
        $this->assertTrue($artifact['no_production_log_read'] ?? false);
        $this->assertTrue($artifact['no_scheduler'] ?? false);
        $this->assertContains('seo_issue_queue', $artifact['protected_tables_not_written'] ?? []);
        $this->assertContains('seo_urls', $artifact['protected_tables_not_written'] ?? []);
        $this->assertSame('CRAWLER-LOG-09', $artifact['next_task'] ?? null);

        foreach (['raw_user_agent', 'metadata_json', 'raw_log_line'] as $field) {
            $this->assertContains($field, $artifact['forbidden_columns'] ?? []);
        }

        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-aggregate-storage-gate.md')));
        $this->assertStringContainsString('does not run production migration', $doc);
        $this->assertStringContainsString('seo_intel_crawler_log_aggregate_write_enabled=true', $doc);
        $this->assertStringContainsString('crawler-log-09', $doc);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(): array
    {
        return [[
            'log_date' => '2026-05-22',
            'host' => 'www.fermatmind.com',
            'surface_family' => 'public_web',
            'bot_family' => 'googlebot',
            'bot_variant' => 'web',
            'bot_verification_state' => 'ua_claim_only',
            'route_family' => 'research',
            'page_entity_type' => 'research_report',
            'canonical_path' => '/en/research/safe-report',
            'path_hash' => null,
            'http_status' => 200,
            'method_bucket' => 'GET',
            'query_present' => false,
            'query_risk_state' => 'none',
            'private_path_blocked' => false,
            'hit_count' => 3,
            'first_seen_at' => '2026-05-22T01:00:00+00:00',
            'last_seen_at' => '2026-05-22T01:05:00+00:00',
            'source_log_family' => 'nginx_openresty_access_log',
            'privacy_transform_version' => 'crawler_log_privacy_transform_v1',
            'raw_user_agent' => 'must-not-persist',
            'metadata_json' => ['must' => 'not-persist'],
        ]];
    }

    private function createAggregateTable(): void
    {
        Schema::connection('seo_intel')->create('seo_crawler_log_daily_aggregates', function ($table): void {
            $table->id();
            $table->date('log_date');
            $table->string('host', 255)->default('unknown_host');
            $table->string('surface_family', 64)->default('unknown');
            $table->string('bot_family', 64)->default('unknown_bot');
            $table->string('bot_variant', 64)->default('unknown');
            $table->string('bot_verification_state', 64)->default('ua_claim_only');
            $table->string('route_family', 64)->default('unknown_public_path');
            $table->string('page_entity_type', 64)->nullable();
            $table->string('canonical_path', 512)->nullable();
            $table->char('path_hash', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('method_bucket', 16)->default('OTHER');
            $table->boolean('query_present')->default(false);
            $table->string('query_risk_state', 64)->default('none');
            $table->boolean('private_path_blocked')->default(false);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('source_log_family', 64)->default('nginx_openresty_access_log');
            $table->string('privacy_transform_version', 64)->default('crawler_log_privacy_transform_v1');
            $table->char('idempotency_key', 64)->unique();
            $table->timestamps();
        });
    }
}
