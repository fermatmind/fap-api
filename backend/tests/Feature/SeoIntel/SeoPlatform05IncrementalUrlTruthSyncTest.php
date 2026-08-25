<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Events\PublicAuthorityChanged;
use App\Listeners\QueueUrlTruthIncrementalSync;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use App\Services\SeoIntel\UrlTruth\IncrementalUrlTruthSyncService;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform05IncrementalUrlTruthSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public function test_publish_rerun_and_retire_are_atomic_idempotent_and_url_truth_only(): void
    {
        $this->prepareSchema();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);
        $source = new class implements UrlTruthInventorySource
        {
            /** @var list<UrlTruthInventoryRecord> */
            public array $records = [];

            public function candidates(): array
            {
                return $this->records;
            }

            public function metadata(): array
            {
                return ['fixture' => true];
            }
        };
        $source->records = [$this->record()];
        $service = new IncrementalUrlTruthSyncService(
            $source,
            new EffectivePublicUrlEvaluator,
            new UrlTruthInventoryRecordWriter,
        );

        $first = $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        DB::connection('seo_intel')->table('seo_url_entities')->update([
            'binding_status' => 'retired',
            'current_binding_key' => null,
        ]);
        $repaired = $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        $rerun = $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');

        self::assertSame('synced', $first['status']);
        self::assertTrue($first['writes_committed']);
        self::assertSame('synced', $repaired['status']);
        self::assertSame('no_change', $rerun['status']);
        self::assertFalse($rerun['writes_committed']);
        self::assertTrue((bool) data_get($first, 'boundaries.url_truth_only'));
        self::assertFalse((bool) data_get($first, 'boundaries.search_submission_allowed', true));
        self::assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        self::assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->whereNotNull('current_binding_key')->count());

        $source->records = [];
        $retired = $service->sync('career_job', 'safe-job', 'en', 'revision-2', 'unpublish');
        $retiredRerun = $service->sync('career_job', 'safe-job', 'en', 'revision-2', 'unpublish');

        self::assertSame('retired', $retired['status']);
        self::assertSame('no_change', $retiredRerun['status']);
        self::assertSame('retired', DB::connection('seo_intel')->table('seo_urls')->value('indexability_state'));
        self::assertSame('retired', DB::connection('seo_intel')->table('seo_url_entities')->value('binding_status'));
        self::assertNull(DB::connection('seo_intel')->table('seo_url_entities')->value('current_binding_key'));
    }

    public function test_canary_inline_listener_executes_the_unified_job_handler(): void
    {
        $this->prepareSchema();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.incremental_sync_inline' => true,
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);
        $source = new class($this->record()) implements UrlTruthInventorySource
        {
            public function __construct(private readonly UrlTruthInventoryRecord $record) {}

            public function candidates(): array
            {
                return [$this->record];
            }

            public function metadata(): array
            {
                return ['fixture' => true];
            }
        };
        app()->instance(IncrementalUrlTruthSyncService::class, new IncrementalUrlTruthSyncService(
            $source,
            new EffectivePublicUrlEvaluator,
            new UrlTruthInventoryRecordWriter,
        ));

        (new QueueUrlTruthIncrementalSync)->handle(
            new PublicAuthorityChanged('career_job', 'safe-job', 'en', 'revision-1', 'publish'),
        );

        self::assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->where('binding_status', 'current')->count());
    }

    private function record(): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: 'https://fermatmind.com/en/career/jobs/safe-job',
            locale: 'en',
            pageEntityType: 'career_job',
            entityIdOrSlug: 'safe-job',
            sourceAuthority: 'career_runtime_publish_projection',
            indexabilityState: 'indexable',
            cluster: 'career',
            entitySource: 'career_directory_authority',
            authorityStatus: 'published_approved',
            metadata: [
                'publication_state' => 'published',
                'robots' => 'index,follow',
                'canonical_self' => true,
                'authority_revision' => 'revision-1',
            ],
            attributes: ['authority_revision' => 'revision-1'],
        );
    }

    private function prepareSchema(): void
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

        Schema::connection('seo_intel')->create('seo_urls', function (Blueprint $table): void {
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
        Schema::connection('seo_intel')->create('seo_url_entities', function (Blueprint $table): void {
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

        $migration = require dirname(__DIR__, 3).'/database/migrations/seo_intel/2026_08_25_020000_expand_url_truth_current_bindings.php';
        $migration->up();
    }
}
