<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Collectors\UrlTruthInventoryCollector;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelUrlTruthInventoryCollectorTest extends TestCase
{
    #[Test]
    public function url_truth_inventory_collector_is_registered_and_default_dry_run_is_safe(): void
    {
        $this->assertContains('url_truth_inventory', config('seo_intel.allowed_collectors'));

        $result = (new SeoIntelCollectorManager)->collect('url_truth_inventory', ['dry_run' => true]);

        $this->assertSame('url_truth_inventory', $result->collector);
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertSame(0, $result->itemsSeen);
        $this->assertFalse((bool) ($result->metadata['fetches_public_html'] ?? true));
        $this->assertFalse((bool) ($result->metadata['performs_drift_detection'] ?? true));
        $this->assertFalse((bool) ($result->metadata['node2_local_laravel_data_source'] ?? true));
    }

    #[Test]
    public function default_write_is_blocked_when_collectors_are_disabled(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('url_truth_inventory', [
            'dry_run' => false,
            'no_write' => false,
        ]);

        $this->assertSame('blocked', $result->status);
        $this->assertFalse($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertContains('collectors_disabled', $result->issues);
    }

    #[Test]
    public function collector_filters_private_flows_forbidden_entities_forbidden_sources_and_pii(): void
    {
        $collector = new UrlTruthInventoryCollector($this->fixtureSource([
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/zh/articles/safe-article',
                locale: 'zh-CN',
                pageEntityType: 'article',
                entityIdOrSlug: 'safe-article',
                sourceAuthority: 'cms_article',
                metadata: ['content_hash' => 'article-safe'],
                attributes: ['entity_hash' => 'article-safe'],
            ),
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/zh/result/private',
                locale: 'zh-CN',
                pageEntityType: 'result',
                entityIdOrSlug: 'private',
                sourceAuthority: 'backend_public_surface',
                isPrivateFlow: true,
            ),
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/zh/orders/private',
                locale: 'zh-CN',
                pageEntityType: 'order',
                entityIdOrSlug: 'private-order',
                sourceAuthority: 'backend_public_surface',
            ),
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/zh/articles/local',
                locale: 'zh-CN',
                pageEntityType: 'article',
                entityIdOrSlug: 'local',
                sourceAuthority: 'node2_local_laravel',
            ),
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/zh/articles/pii',
                locale: 'zh-CN',
                pageEntityType: 'article',
                entityIdOrSlug: 'pii',
                sourceAuthority: 'cms_article',
                metadata: ['email_hash' => 'blocked'],
            ),
        ]));

        $result = $collector->collect(['dry_run' => true, 'writes_allowed' => false]);

        $this->assertSame('success', $result->status);
        $this->assertSame(5, $result->itemsSeen);
        $this->assertSame(1, $result->metadata['planned_url_count'] ?? null);
        $this->assertSame(1, $result->metadata['planned_entity_count'] ?? null);
        $this->assertSame(1, $result->metadata['skipped_private_flows'] ?? null);
        $this->assertSame(1, $result->metadata['skipped_forbidden_entity_types'] ?? null);
        $this->assertSame(1, $result->metadata['skipped_forbidden_source_authorities'] ?? null);
        $this->assertContains('skipped_private_flow', $result->issues);
        $this->assertContains('skipped_forbidden_page_entity_type:order', $result->issues);
        $this->assertContains('skipped_forbidden_source_authority:node2_local_laravel', $result->issues);
        $this->assertContains('skipped_forbidden_detail_key', $result->issues);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertFalse($result->writesAttempted);
        $this->assertNotEmpty($result->metadata['sample_hashes'] ?? []);
    }

    #[Test]
    public function write_path_can_commit_to_test_seo_intel_connection_only_when_allowed(): void
    {
        $this->prepareSeoIntelSqliteConnection();

        $collector = new UrlTruthInventoryCollector($this->fixtureSource([
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
                locale: 'en',
                pageEntityType: 'test_detail',
                entityIdOrSlug: 'mbti-personality-test-16-personality-types',
                sourceAuthority: 'scale_catalog',
                metadata: ['content_hash' => 'mbti'],
                attributes: ['scale_hash' => 'mbti'],
            ),
        ]));

        $result = $collector->collect(['dry_run' => false, 'writes_allowed' => true]);

        $this->assertSame('success', $result->status);
        $this->assertTrue($result->writesAttempted);
        $this->assertTrue($result->writesCommitted);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    #[Test]
    public function url_truth_inventory_command_outputs_safe_dry_run_json(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'url_truth_inventory',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('url_truth_inventory', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));

        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'cookie', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    #[Test]
    public function generated_artifact_locks_url_truth_inventory_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-01B', $artifact['source_documents'] ?? []);
        $this->assertSame('url_truth_inventory', $artifact['collector'] ?? null);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['fetches_public_html'] ?? true));
        $this->assertFalse((bool) ($artifact['performs_drift_detection'] ?? true));
        $this->assertContains('article', $artifact['allowed_page_entity_types'] ?? []);
        $this->assertContains('result', $artifact['forbidden_page_entity_types'] ?? []);
        $this->assertContains('cms_article', $artifact['allowed_source_authorities'] ?? []);
        $this->assertContains('node2_local_laravel', $artifact['forbidden_source_authorities'] ?? []);
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertFalse((bool) ($artifact['node2_local_laravel_data_source'] ?? true));
        $this->assertSame('SEO-DASH-02B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function url_truth_inventory_does_not_add_scheduler_activation(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('UrlTruthInventoryCollector', $bootstrap);
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     */
    private function fixtureSource(array $records): UrlTruthInventorySource
    {
        return new class($records) implements UrlTruthInventorySource
        {
            /**
             * @param  list<UrlTruthInventoryRecord>  $records
             */
            public function __construct(private readonly array $records) {}

            public function candidates(): array
            {
                return $this->records;
            }

            public function metadata(): array
            {
                return [
                    'source' => 'fixture_backend_authority',
                    'external_api_calls' => false,
                    'fetches_public_html' => false,
                    'node2_local_laravel_data_source' => false,
                ];
            }
        };
    }

    private function prepareSeoIntelSqliteConnection(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source', 64)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });

        Schema::connection('seo_intel')->create('seo_url_entities', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-url-truth-inventory.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
