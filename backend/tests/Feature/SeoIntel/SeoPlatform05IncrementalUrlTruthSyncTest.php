<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Events\PublicAuthorityChanged;
use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;
use App\Listeners\QueueUrlTruthIncrementalSync;
use App\Models\CareerGuide;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use App\Services\SeoIntel\UrlTruth\IncrementalUrlTruthSyncService;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
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

    public function test_guide_publication_dispatches_only_after_commit_and_rollback_dispatches_nothing(): void
    {
        $this->prepareSchema();
        $this->prepareGuideSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        Bus::fake([SyncPublicAuthorityUrlTruth::class]);
        $connection = DB::connection('seo_intel');
        $connection->beginTransaction();
        $guide = $this->createGuide();
        Bus::assertNotDispatched(SyncPublicAuthorityUrlTruth::class);
        $connection->commit();
        Bus::assertDispatched(SyncPublicAuthorityUrlTruth::class, fn ($job): bool => $job->pageEntityType === 'career_guide'
            && $job->entityIdentity === (string) $guide->id
            && $job->locale === 'en'
            && preg_match('/^[a-f0-9]{64}$/', $job->revision) === 1);
        Bus::assertDispatchedTimes(SyncPublicAuthorityUrlTruth::class, 1);

        $connection->beginTransaction();
        $guide->update(['slug' => 'rolled-back-guide']);
        $connection->rollBack();
        Bus::assertDispatchedTimes(SyncPublicAuthorityUrlTruth::class, 1);
        self::assertSame('safe-guide', $connection->table('career_guides')->value('slug'));
    }

    public function test_guide_draft_tenant_and_disabled_write_events_do_not_dispatch_jobs(): void
    {
        $this->prepareSchema();
        $this->prepareGuideSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        Bus::fake([SyncPublicAuthorityUrlTruth::class]);
        $this->createGuide(['status' => 'draft']);
        $this->createGuide(['is_public' => false]);
        $this->createGuide(['is_indexable' => false]);
        $this->createGuide(['org_id' => 7]);
        config(['seo_intel.write_enabled' => false]);
        $this->createGuide();
        Bus::assertNotDispatched(SyncPublicAuthorityUrlTruth::class);
    }

    public function test_guide_changed_locale_and_unpublication_keep_the_old_identity_syncable(): void
    {
        $this->prepareSchema();
        $this->prepareGuideSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        Bus::fake([SyncPublicAuthorityUrlTruth::class]);
        $guide = $this->createGuide();
        $guide->save();
        Bus::assertDispatchedTimes(SyncPublicAuthorityUrlTruth::class, 1);
        $guide->update(['locale' => 'zh-CN']);
        Bus::assertDispatchedTimes(SyncPublicAuthorityUrlTruth::class, 3);
        $guide->update(['status' => 'draft']);
        Bus::assertDispatchedTimes(SyncPublicAuthorityUrlTruth::class, 4);
        Bus::assertDispatched(SyncPublicAuthorityUrlTruth::class, fn ($job): bool => $job->locale === 'en' && $job->change === 'unpublish');
        Bus::assertDispatched(SyncPublicAuthorityUrlTruth::class, fn ($job): bool => $job->locale === 'zh-CN' && $job->change === 'unpublish');
    }

    public function test_incremental_readback_failure_rolls_back_url_and_binding(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $record = $this->record();
        $source = new class($record) implements UrlTruthInventorySource
        {
            public function __construct(private UrlTruthInventoryRecord $record) {}

            public function candidates(): array
            {
                return [$this->record];
            }

            public function metadata(): array
            {
                return [];
            }
        };
        $connection = DB::connection('seo_intel');
        $connection->unprepared("CREATE TRIGGER corrupt_truth AFTER INSERT ON seo_urls BEGIN UPDATE seo_urls SET entity_id_or_slug = 'wrong-identity' WHERE id = NEW.id; END");
        $service = new IncrementalUrlTruthSyncService($source, new EffectivePublicUrlEvaluator, new UrlTruthInventoryRecordWriter);
        $failure = null;
        try {
            $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('readback', $failure->getMessage());
        self::assertSame(0, $connection->table('seo_urls')->count());
        self::assertSame(0, $connection->table('seo_url_entities')->count());
    }

    public function test_incremental_sync_does_not_overwrite_another_identity_at_the_same_url(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $record = $this->record();
        $source = new class($record) implements UrlTruthInventorySource
        {
            public function __construct(private UrlTruthInventoryRecord $record) {}

            public function candidates(): array
            {
                return [$this->record];
            }

            public function metadata(): array
            {
                return [];
            }
        };
        (new UrlTruthInventoryRecordWriter)->write([$record]);
        $connection = DB::connection('seo_intel');
        $connection->table('seo_urls')->update(['entity_id_or_slug' => 'another-identity']);
        $before = $connection->table('seo_urls')->get()->toJson();
        $service = new IncrementalUrlTruthSyncService($source, new EffectivePublicUrlEvaluator, new UrlTruthInventoryRecordWriter);
        $failure = null;
        try {
            $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('conflict', $failure->getMessage());
        self::assertSame($before, $connection->table('seo_urls')->get()->toJson());
    }

    public function test_authority_change_during_incremental_sync_rolls_back(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $source = new class($this->record()) implements UrlTruthInventorySource
        {
            private int $reads = 0;

            public function __construct(private UrlTruthInventoryRecord $record) {}

            public function candidates(): array
            {
                return ++$this->reads === 1 ? [$this->record] : [];
            }

            public function metadata(): array
            {
                return [];
            }
        };
        $service = new IncrementalUrlTruthSyncService($source, new EffectivePublicUrlEvaluator, new UrlTruthInventoryRecordWriter);
        $failure = null;
        try {
            $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('authority changed', $failure->getMessage());
        self::assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        self::assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    public function test_wrong_binding_repair_preserves_history_and_non_target_semantics(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $record = $this->record();
        $other = $this->record('another-job');
        $source = new class($record, $other) implements UrlTruthInventorySource
        {
            public function __construct(private UrlTruthInventoryRecord $record, private UrlTruthInventoryRecord $other) {}

            public function candidates(): array
            {
                return [$this->record, $this->other];
            }

            public function metadata(): array
            {
                return [];
            }
        };
        (new UrlTruthInventoryRecordWriter)->write([$record, $other]);
        $connection = DB::connection('seo_intel');
        $otherBefore = $connection->table('seo_urls')->where('entity_id_or_slug', 'another-job')->get()->toJson();
        $connection->table('seo_url_entities')->where('entity_id_or_slug', 'safe-job')->update(['canonical_url_hash' => hash('sha256', 'old-canonical')]);
        $service = new IncrementalUrlTruthSyncService($source, new EffectivePublicUrlEvaluator, new UrlTruthInventoryRecordWriter);
        self::assertSame('synced', $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish')['status']);
        self::assertSame($otherBefore, $connection->table('seo_urls')->where('entity_id_or_slug', 'another-job')->get()->toJson());
        self::assertSame(1, $connection->table('seo_url_entities')->where('entity_id_or_slug', 'safe-job')->where('binding_status', 'current')->count());
        self::assertSame(1, $connection->table('seo_url_entities')->where('entity_id_or_slug', 'safe-job')->where('binding_status', 'superseded_canonical')->whereNotNull('superseded_by_id')->count());
        $beforeRerun = $connection->table('seo_url_entities')->orderBy('id')->get()->toJson();
        self::assertSame('no_change', $service->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish')['status']);
        self::assertSame($beforeRerun, $connection->table('seo_url_entities')->orderBy('id')->get()->toJson());
    }

    public function test_duplicate_canonical_binding_is_rejected_without_mutating_history(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $record = $this->record();
        $source = new class($record) implements UrlTruthInventorySource
        {
            public function __construct(private UrlTruthInventoryRecord $record) {}

            public function candidates(): array
            {
                return [$this->record];
            }

            public function metadata(): array
            {
                return [];
            }
        };
        (new UrlTruthInventoryRecordWriter)->write([$record]);
        $connection = DB::connection('seo_intel');
        $duplicate = (array) $connection->table('seo_url_entities')->first();
        unset($duplicate['id']);
        $duplicate['entity_id_or_slug'] = 'other-identity';
        $duplicate['current_binding_key'] = hash('sha256', 'other-key');
        $connection->table('seo_url_entities')->insert($duplicate);
        $before = $connection->table('seo_url_entities')->orderBy('id')->get()->toJson();
        $failure = null;
        try {
            (new IncrementalUrlTruthSyncService($source, new EffectivePublicUrlEvaluator, new UrlTruthInventoryRecordWriter))
                ->sync('career_job', 'safe-job', 'en', 'revision-1', 'publish');
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('canonical binding conflict', $failure->getMessage());
        self::assertSame($before, $connection->table('seo_url_entities')->orderBy('id')->get()->toJson());
    }

    private function prepareGuideSchema(): void
    {
        Schema::connection('seo_intel')->create('career_guides', function (Blueprint $table): void {
            $table->id();
            $table->integer('org_id');
            $table->string('slug');
            $table->string('locale');
            $table->string('status');
            $table->boolean('is_public');
            $table->boolean('is_indexable');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    private function createGuide(array $attributes = []): CareerGuide
    {
        $guide = new CareerGuide;
        $guide->setConnection('seo_intel');
        $guide->forceFill($attributes + [
            'org_id' => 0, 'slug' => 'safe-guide', 'locale' => 'en',
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ])->save();

        return $guide;
    }

    private function record(string $slug = 'safe-job'): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: 'https://fermatmind.com/en/career/jobs/'.$slug,
            locale: 'en',
            pageEntityType: 'career_job',
            entityIdOrSlug: $slug,
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
