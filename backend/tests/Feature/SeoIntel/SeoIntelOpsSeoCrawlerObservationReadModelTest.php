<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\AbstractSeoDashboardReadService;
use App\Services\SeoIntel\OpsDashboard\SeoCrawlerLogObservationReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoCrawlerObservationReadModelTest extends TestCase
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
        ]);

        DB::purge('seo_intel');
        $this->createAggregateTable();
        $this->seedAggregateRows();
    }

    #[Test]
    public function crawler_observation_read_model_returns_safe_aggregates_and_recent_rows(): void
    {
        $payload = (new SeoCrawlerLogObservationReadService)->read(limit: 2);

        $this->assertSame(4, $payload['total_count']);
        $this->assertSame(15, $payload['total_hits']);
        $this->assertSame([
            ['label' => 'baiduspider', 'count' => 1],
            ['label' => 'bingbot', 'count' => 1],
            ['label' => 'googlebot', 'count' => 1],
            ['label' => 'unknown_bot', 'count' => 1],
        ], $payload['aggregates']['bot_family']);
        $this->assertSame([
            ['label' => 'api', 'count' => 1],
            ['label' => 'public_web', 'count' => 3],
        ], $payload['aggregates']['surface_family']);
        $this->assertSame([
            ['label' => '200', 'count' => 2],
            ['label' => '404', 'count' => 1],
            ['label' => '500', 'count' => 1],
        ], $payload['aggregates']['http_status']);
        $this->assertSame([
            'private_path_blocked_count' => 1,
            'sensitive_query_count' => 1,
            'api_or_ops_surface_count' => 1,
            'unknown_bot_count' => 1,
        ], $payload['safety_counts']);
        $this->assertCount(2, $payload['recent_rows']);
        $this->assertSame([
            'log_date',
            'host',
            'surface_family',
            'bot_family',
            'bot_variant',
            'bot_verification_state',
            'route_family',
            'page_entity_type',
            'canonical_path',
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
            'updated_at',
        ], array_keys($payload['recent_rows'][0]));
        $this->assertArrayNotHasKey('path_hash', $payload['recent_rows'][0]);
        $this->assertArrayNotHasKey('raw_user_agent', $payload['recent_rows'][0]);
        $this->assertArrayNotHasKey('raw_request_uri', $payload['recent_rows'][0]);
        $this->assertArrayNotHasKey('metadata_json', $payload['recent_rows'][0]);
    }

    #[Test]
    public function crawler_observation_read_model_is_read_only_and_uses_only_aggregate_table(): void
    {
        $connection = DB::connection('seo_intel');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        (new SeoCrawlerLogObservationReadService)->read(limit: 3);

        $sql = strtolower(implode("\n", array_map(
            static fn (array $entry): string => (string) ($entry['query'] ?? ''),
            $connection->getQueryLog(),
        )));

        $this->assertContains(
            'seo_crawler_log_daily_aggregates',
            AbstractSeoDashboardReadService::allowedTables(),
        );
        $this->assertStringContainsString('seo_crawler_log_daily_aggregates', $sql);

        foreach ([
            'insert into',
            'update ',
            'delete from',
            'drop table',
            'seo_crawler_logs_daily',
            'seo_urls',
            'seo_issue_queue',
            'orders',
            'payment_events',
            'users',
            'event_payload',
            'metadata_json',
            'raw_user_agent',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    #[Test]
    public function docs_and_artifact_lock_crawler_observation_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-ops-observation-read-model.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/crawler-log-ops-observation-read-model.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'crawler-log-09',
            'ops seo crawler observation read model',
            'seo_crawler_log_daily_aggregates',
            'read-only',
            'no raw log read',
            'no raw persistence',
            'no issue queue write',
            'no url truth write',
            'no search submission',
            'next task: `crawler-log-10`',
            '"next_task": "crawler-log-10"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    private function createAggregateTable(): void
    {
        Schema::connection('seo_intel')->create('seo_crawler_log_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->date('log_date');
            $table->string('host', 128);
            $table->string('surface_family', 64);
            $table->string('bot_family', 64);
            $table->string('bot_variant', 64);
            $table->string('bot_verification_state', 64);
            $table->string('route_family', 96);
            $table->string('page_entity_type', 64)->nullable();
            $table->string('canonical_path', 1024)->nullable();
            $table->string('path_hash', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('method_bucket', 16);
            $table->boolean('query_present');
            $table->string('query_risk_state', 64);
            $table->boolean('private_path_blocked');
            $table->unsignedInteger('hit_count');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('source_log_family', 64);
            $table->string('privacy_transform_version', 64);
            $table->string('idempotency_key', 64);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function seedAggregateRows(): void
    {
        foreach ([
            [
                'log_date' => '2026-05-21',
                'host' => 'fermatmind.com',
                'surface_family' => 'public_web',
                'bot_family' => 'googlebot',
                'route_family' => 'research',
                'page_entity_type' => 'research_report',
                'canonical_path' => '/en/research/hrzone-canary',
                'http_status' => 200,
                'query_risk_state' => 'tracking_only',
                'private_path_blocked' => false,
                'hit_count' => 6,
                'last_seen_at' => '2026-05-21 23:20:00',
            ],
            [
                'log_date' => '2026-05-21',
                'host' => 'fermatmind.com',
                'surface_family' => 'public_web',
                'bot_family' => 'baiduspider',
                'route_family' => 'test_detail',
                'page_entity_type' => 'test_detail',
                'canonical_path' => '/zh/tests/mbti-personality-test-16-personality-types',
                'http_status' => 200,
                'query_risk_state' => 'none',
                'private_path_blocked' => false,
                'hit_count' => 5,
                'last_seen_at' => '2026-05-21 23:18:00',
            ],
            [
                'log_date' => '2026-05-21',
                'host' => 'fermatmind.com',
                'surface_family' => 'public_web',
                'bot_family' => 'bingbot',
                'route_family' => 'blocked_private_path',
                'page_entity_type' => null,
                'canonical_path' => null,
                'http_status' => 404,
                'query_risk_state' => 'sensitive_key_present',
                'private_path_blocked' => true,
                'hit_count' => 3,
                'last_seen_at' => '2026-05-21 23:16:00',
            ],
            [
                'log_date' => '2026-05-21',
                'host' => 'api.fermatmind.com',
                'surface_family' => 'api',
                'bot_family' => 'unknown_bot',
                'route_family' => 'api',
                'page_entity_type' => null,
                'canonical_path' => null,
                'http_status' => 500,
                'query_risk_state' => 'unknown_query_present',
                'private_path_blocked' => false,
                'hit_count' => 1,
                'last_seen_at' => '2026-05-21 23:14:00',
            ],
        ] as $index => $row) {
            DB::connection('seo_intel')->table('seo_crawler_log_daily_aggregates')->insert($row + [
                'bot_variant' => 'web',
                'bot_verification_state' => 'ua_claim_only',
                'method_bucket' => 'GET',
                'query_present' => $row['query_risk_state'] !== 'none',
                'first_seen_at' => '2026-05-21 23:00:00',
                'source_log_family' => 'nginx_openresty_access_log',
                'privacy_transform_version' => 'crawler_log_privacy_transform_v1',
                'idempotency_key' => hash('sha256', 'crawler-log-observation-'.$index),
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => $row['last_seen_at'],
            ]);
        }
    }
}
