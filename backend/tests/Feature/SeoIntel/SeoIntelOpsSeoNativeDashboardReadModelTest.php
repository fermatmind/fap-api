<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\AbstractSeoDashboardReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardOverviewReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoSearchChannelQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoUrlTruthReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoNativeDashboardReadModelTest extends TestCase
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
        $this->createSeoIntelTables();
        $this->seedSeoIntelData();
    }

    #[Test]
    public function overview_service_returns_live_heartbeat_and_safety_cards(): void
    {
        $payload = (new SeoDashboardOverviewReadService)->read();

        $this->assertSame([
            ['key' => 'url_truth_total', 'label' => 'URL Truth URLs', 'value' => 9],
            ['key' => 'url_entity_mapping_total', 'label' => 'URL Entities', 'value' => 9],
            ['key' => 'issue_queue_total', 'label' => 'Issue Queue', 'value' => 5],
            ['key' => 'search_channel_queue_item_total', 'label' => 'Search Channel Queue Items', 'value' => 1],
            ['key' => 'search_channel_queue_batch_total', 'label' => 'Search Channel Queue Batches', 'value' => 1],
            ['key' => 'search_channel_queue_event_total', 'label' => 'Search Channel Events', 'value' => 4],
        ], $payload['heartbeat']);

        $this->assertSame([
            ['key' => 'private_flow_count', 'label' => 'Private-flow leaks', 'value' => 0, 'alert' => false],
            ['key' => 'forbidden_authority_count', 'label' => 'Forbidden authority', 'value' => 0, 'alert' => false],
            ['key' => 'claim_unsafe_count', 'label' => 'Claim unsafe', 'value' => 0, 'alert' => false],
        ], $payload['safety']);
    }

    #[Test]
    public function url_truth_read_service_returns_required_distributions_and_safety_counts(): void
    {
        $payload = (new SeoUrlTruthReadService)->read();

        $this->assertSame(9, $payload['total_count']);
        $this->assertSame([
            ['label' => 'home', 'count' => 2],
            ['label' => 'research_report', 'count' => 2],
            ['label' => 'test_detail', 'count' => 3],
            ['label' => 'test_hub', 'count' => 2],
        ], $payload['distributions']['page_entity_type']);
        $this->assertSame([
            ['label' => 'en', 'count' => 6],
            ['label' => 'zh-CN', 'count' => 3],
        ], $payload['distributions']['locale']);
        $this->assertSame([
            ['label' => 'backend_cms', 'count' => 2],
            ['label' => 'backend_public_surface', 'count' => 4],
            ['label' => 'scale_catalog', 'count' => 3],
        ], $payload['distributions']['source_authority']);
        $this->assertSame([
            ['label' => 'indexable', 'count' => 9],
        ], $payload['distributions']['indexability_state']);
        $this->assertSame([
            'private_flow_count' => 0,
            'forbidden_authority_count' => 0,
            'claim_unsafe_count' => 0,
        ], $payload['safety_counts']);
    }

    #[Test]
    public function issue_queue_and_search_channel_recent_rows_are_limited_to_safe_fields(): void
    {
        $issues = (new SeoIssueQueueReadService)->read(limit: 2);
        $queue = (new SeoSearchChannelQueueReadService)->read(limit: 1);

        $this->assertSame([
            ['label' => 'missing_lastmod_for_indexable_url', 'count' => 5],
        ], $issues['aggregates']['issue_type']);
        $this->assertSame([
            ['label' => 'info', 'count' => 5],
        ], $issues['aggregates']['severity']);
        $this->assertSame([
            ['label' => 'open', 'count' => 5],
        ], $issues['aggregates']['status']);
        $this->assertCount(2, $issues['recent_rows']);
        $this->assertSame([
            'canonical_path',
            'locale',
            'page_entity_type',
            'issue_type',
            'severity',
            'source_system',
            'source_engine',
            'status',
            'lifecycle_state',
            'detected_at',
            'updated_at',
        ], array_keys($issues['recent_rows'][0]));
        $this->assertArrayNotHasKey('metadata_json', $issues['recent_rows'][0]);
        $this->assertArrayNotHasKey('evidence_hash', $issues['recent_rows'][0]);

        $this->assertSame([
            ['label' => 'indexnow', 'count' => 1],
        ], $queue['aggregates']['channel']);
        $this->assertSame([
            ['label' => 'approved', 'count' => 1],
        ], $queue['aggregates']['approval_state']);
        $this->assertSame([
            ['label' => 'submitted', 'count' => 1],
        ], $queue['aggregates']['execution_state']);
        $this->assertSame([
            ['event_type' => 'batch_created', 'count' => 1, 'latest_created_at' => '2026-05-21 23:20:00'],
            ['event_type' => 'live_submission_approved', 'count' => 1, 'latest_created_at' => '2026-05-21 23:25:00'],
            ['event_type' => 'live_submission_response', 'count' => 1, 'latest_created_at' => '2026-05-21 23:26:00'],
            ['event_type' => 'queue_item_planned', 'count' => 1, 'latest_created_at' => '2026-05-21 23:21:00'],
        ], $queue['aggregates']['event_type']);
        $this->assertSame([
            'canonical_path',
            'locale',
            'page_entity_type',
            'source_authority',
            'channel',
            'eligibility_state',
            'approval_state',
            'execution_state',
            'indexability_state',
            'claim_boundary_state',
            'private_flow',
            'approved_at',
            'created_at',
            'updated_at',
        ], array_keys($queue['recent_rows'][0]));
        $this->assertArrayNotHasKey('reason_codes', $queue['recent_rows'][0]);
        $this->assertArrayNotHasKey('event_payload', $queue['recent_rows'][0]);
    }

    #[Test]
    public function services_stay_inside_allowed_tables_and_execute_no_writes(): void
    {
        $connection = DB::connection('seo_intel');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        (new SeoDashboardOverviewReadService)->read();
        (new SeoUrlTruthReadService)->read();
        (new SeoIssueQueueReadService)->read(limit: 3);
        (new SeoSearchChannelQueueReadService)->read(limit: 3);

        $sql = strtolower(implode("\n", array_map(
            static fn (array $entry): string => (string) ($entry['query'] ?? ''),
            $connection->getQueryLog(),
        )));

        foreach (AbstractSeoDashboardReadService::allowedTables() as $table) {
            $this->assertStringContainsString($table, $sql);
        }

        foreach ([
            'insert into',
            'update ',
            'delete from',
            'drop table',
            'orders',
            'payment_events',
            'reports',
            'users',
            'seo_baidu_push_logs',
            'seo_indexnow_submissions',
            'seo_domestic_submission_logs',
            'seo_crawler_logs_daily',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_read_only_dashboard_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-seo-native-dashboard-read-model.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-seo-native-dashboard-read-model.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ops-seo-native-dash-01',
            'native read-only /ops/seo dashboard read model',
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
            'seo_search_channel_queue_items',
            'seo_search_channel_queue_batches',
            'seo_search_channel_queue_events',
            'service-only read model',
            'no writes',
            'no metabase',
            'no raw sql for operators',
            'next task: `ops-seo-native-dash-02`',
            '"next_task": "ops-seo-native-dash-02"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    private function createSeoIntelTables(): void
    {
        $schema = Schema::connection('seo_intel');

        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128);
            $table->string('issue_type', 64);
            $table->string('severity', 32);
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 64);
            $table->string('status', 64);
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('channel', 64);
            $table->string('eligibility_state', 64);
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64);
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedSeoIntelData(): void
    {
        $urls = [
            ['https://fermatmind.com/en', 'en', 'home', 'home-en', 'backend_public_surface'],
            ['https://fermatmind.com/zh', 'zh-CN', 'home', 'home-zh', 'backend_public_surface'],
            ['https://fermatmind.com/en/tests', 'en', 'test_hub', 'tests-en', 'backend_public_surface'],
            ['https://fermatmind.com/zh/tests', 'zh-CN', 'test_hub', 'tests-zh', 'backend_public_surface'],
            ['https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', 'en', 'test_detail', 'mbti', 'scale_catalog'],
            ['https://fermatmind.com/en/tests/big-five-personality-test-ocean-model', 'en', 'test_detail', 'big-five', 'scale_catalog'],
            ['https://fermatmind.com/zh/tests/enneagram-personality-test-nine-types', 'zh-CN', 'test_detail', 'enneagram', 'scale_catalog'],
            ['https://fermatmind.com/en/research/hrzone-canary', 'en', 'research_report', 'hrzone-en', 'backend_cms'],
            ['https://fermatmind.com/en/research/search-channel-preflight', 'en', 'research_report', 'preflight-en', 'backend_cms'],
        ];

        foreach ($urls as [$url, $locale, $pageType, $entityId, $authority]) {
            DB::connection('seo_intel')->table('seo_urls')->insert([
                'canonical_url' => $url,
                'locale' => $locale,
                'page_entity_type' => $pageType,
                'entity_id_or_slug' => $entityId,
                'source_authority' => $authority,
                'indexability_state' => 'indexable',
                'is_private_flow' => false,
                'metadata_json' => json_encode(['claim_boundary_state' => 'claim_safe'], JSON_UNESCAPED_SLASHES),
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => '2026-05-21 23:00:00',
            ]);

            DB::connection('seo_intel')->table('seo_url_entities')->insert([
                'locale' => $locale,
                'page_entity_type' => $pageType,
                'entity_id_or_slug' => $entityId,
                'entity_source' => $authority,
                'authority_status' => 'approved',
                'source_updated_at' => '2026-05-21 23:00:00',
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => '2026-05-21 23:00:00',
            ]);
        }

        foreach (range(1, 5) as $index) {
            DB::connection('seo_intel')->table('seo_issue_queue')->insert([
                'issue_uid' => 'issue-'.$index,
                'issue_type' => 'missing_lastmod_for_indexable_url',
                'severity' => 'info',
                'source_system' => 'url_truth_inventory',
                'source_engine' => null,
                'canonical_url' => 'https://fermatmind.com/en/research/issue-'.$index,
                'locale' => 'en',
                'page_entity_type' => 'research_report',
                'status' => 'open',
                'lifecycle_state' => 'open',
                'detected_at' => sprintf('2026-05-21 23:0%d:00', $index),
                'metadata_json' => json_encode(['raw_evidence_included' => false], JSON_UNESCAPED_SLASHES),
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => sprintf('2026-05-21 23:1%d:00', $index),
            ]);
        }

        DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->insert([
            'id' => 1,
            'channel' => 'indexnow',
            'status' => 'submitted',
            'item_count' => 1,
            'created_at' => '2026-05-21 23:20:00',
            'updated_at' => '2026-05-21 23:26:00',
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'batch_id' => 1,
            'canonical_url' => 'https://fermatmind.com/en',
            'locale' => 'en',
            'page_entity_type' => 'home',
            'source_authority' => 'backend_public_surface',
            'channel' => 'indexnow',
            'eligibility_state' => 'eligible',
            'approval_state' => 'approved',
            'execution_state' => 'submitted',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_UNESCAPED_SLASHES),
            'approved_at' => '2026-05-21 23:24:00',
            'created_at' => '2026-05-21 23:20:00',
            'updated_at' => '2026-05-21 23:26:00',
        ]);

        foreach ([
            ['queue_item_planned', '2026-05-21 23:21:00'],
            ['batch_created', '2026-05-21 23:20:00'],
            ['live_submission_approved', '2026-05-21 23:25:00'],
            ['live_submission_response', '2026-05-21 23:26:00'],
        ] as [$type, $createdAt]) {
            DB::connection('seo_intel')->table('seo_search_channel_queue_events')->insert([
                'queue_item_id' => 1,
                'batch_id' => 1,
                'event_type' => $type,
                'event_payload' => json_encode(['safe' => true], JSON_UNESCAPED_SLASHES),
                'created_at' => $createdAt,
            ]);
        }
    }
}
