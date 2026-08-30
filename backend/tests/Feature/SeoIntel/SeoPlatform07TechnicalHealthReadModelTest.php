<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoTechnicalHealthReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07TechnicalHealthReadModelTest extends TestCase
{
    private const CONNECTION = 'seo_platform_07_health_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        Schema::connection(self::CONNECTION)->create('seo_runtime_probe_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('slot_key');
            $table->string('trigger_mode');
            $table->string('status');
            $table->timestamp('scheduled_for');
            $table->timestamp('completed_at');
            $table->string('receipt_hash');
            $table->json('crawler_source_receipt_json');
            $table->json('receipt_json');
            $table->timestamps();
        });
        Schema::connection(self::CONNECTION)->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('cluster_uid')->nullable();
            $table->string('detector_id')->nullable();
            $table->string('severity');
            $table->string('page_entity_type');
            $table->string('locale');
            $table->string('status');
            $table->timestamp('detected_at');
            $table->timestamp('last_evidence_at')->nullable();
            $table->unsignedInteger('affected_url_count');
        });
    }

    #[Test]
    public function unified_read_model_keeps_zero_distinct_from_missing_and_sanitizes_evidence(): void
    {
        $result = (new SeoTechnicalHealthReadService(self::CONNECTION))->read();

        $this->assertSame('production_unproven', $result['state']);
        $this->assertSame(0, data_get($result, 'metrics.scheduled_slot_count'));
        $this->assertNull(data_get($result, 'metrics.crawler_hit_count'));
        $this->assertSame(0, data_get($result, 'metrics.open_cluster_count'));
        $this->assertNull(data_get($result, 'metrics.incident_rate'));
        $this->assertSame('生产尚未证明', data_get($result, 'status_labels.zh-CN.production_unproven'));
        $this->assertSame('Production unproven', data_get($result, 'status_labels.en.production_unproven'));
        foreach (['raw_url_exposed', 'query_exposed', 'user_agent_exposed', 'response_body_exposed', 'raw_topology_exposed'] as $key) {
            $this->assertFalse(data_get($result, 'evidence_summary.'.$key));
        }
    }

    #[Test]
    public function cluster_projection_contains_only_sanitized_aggregate_fields(): void
    {
        DB::connection(self::CONNECTION)->table('seo_issue_queue')->insert([
            'cluster_uid' => str_repeat('a', 64), 'detector_id' => 'http_5xx', 'severity' => 'high',
            'page_entity_type' => 'articles_topics', 'locale' => 'en', 'status' => 'open',
            'detected_at' => '2026-08-26 09:00:00', 'last_evidence_at' => '2026-08-26 09:10:00', 'affected_url_count' => 3,
        ]);

        $result = (new SeoTechnicalHealthReadService(self::CONNECTION))->read();
        $this->assertSame('http_5xx', data_get($result, 'clusters.0.detector'));
        $this->assertSame(3, data_get($result, 'clusters.0.affected_count'));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['https://private.example', '?secret=', 'Googlebot/2.1', '<html', 'node-10.internal'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function incomplete_optional_cluster_projection_does_not_hide_runtime_health(): void
    {
        $connection = self::CONNECTION.'_incomplete';
        config(['database.connections.'.$connection => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge($connection);
        Schema::connection($connection)->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('cluster_uid')->nullable();
            $table->string('detector_id')->nullable();
        });

        $result = (new SeoTechnicalHealthReadService($connection))->read();

        $this->assertSame('seo-platform-07-technical-health.v1', $result['schema_version']);
        $this->assertSame([], $result['clusters']);
        $this->assertTrue(data_get($result, 'boundaries.read_only'));
        $this->assertFalse(data_get($result, 'boundaries.write_authorization_granted'));
    }
}
