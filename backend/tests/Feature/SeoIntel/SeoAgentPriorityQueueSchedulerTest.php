<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentPriorityQueueSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://fermatmind.com',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.search_channel_queue.live_submission.allowed_channels' => ['indexnow', 'baidu_push'],
            'seo_intel.search_channel_queue.live_submission.allowed_hosts' => ['fermatmind.com'],
            'seo_intel.search_channel_queue.live_submission.indexnow.endpoint' => 'https://api.indexnow.test/indexnow',
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'secret-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/indexnow.txt',
            'seo_intel.search_channel_queue.approved_source_authorities' => [
                'backend_cms',
                'backend_public_surface',
                'scale_catalog',
            ],
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function command_orchestrates_weekly_l5_low_risk_path_with_indexnow_only(): void
    {
        Http::fake([
            'api.indexnow.test/*' => Http::response('', 202),
        ]);
        $page = $this->createContentPage();
        $this->seedSeoUrl('https://fermatmind.com/zh/priority-scheduler-page', (string) $page->id);

        $artifactDir = $this->artifactDir();
        $exitCode = Artisan::call('seo-agent:priority-queue-scheduler', [
            '--mode' => 'weekly-l5-low-risk',
            '--sources' => 'cms-tdk-gap',
            '--limit' => 10,
            '--draft-limit' => 10,
            '--publish-limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('seo-agent-priority-queue-scheduler.v1', $summary['schema_version'] ?? null);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(1, data_get($summary, 'steps.weekly_draft_write_auto.rows_created'));
        $this->assertSame(1, data_get($summary, 'steps.cms_publish_auto_canary.published_or_planned_count'));
        $this->assertSame(1, data_get($summary, 'steps.post_publish_indexnow_auto.submitted_count'));
        $this->assertSame('pass', data_get($summary, 'steps.rollback_post_publish.status'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.google_indexing_api_call', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.queue_worker_started', true));

        $fresh = $page->refresh();
        $this->assertSame('passed', (string) $fresh->claim_gate_status);
        $this->assertSame('approved', (string) $fresh->review_state);
        $this->assertNotSame(88, (int) $fresh->published_revision_id);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $queueItem = DB::connection('seo_intel')->table('seo_search_channel_queue_items')->first();
        $this->assertSame('indexnow', (string) $queueItem->channel);
        $this->assertSame('submitted', (string) $queueItem->execution_state);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && $request['urlList'] === ['https://fermatmind.com/zh/priority-scheduler-page'];
        });

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('SEO-AGENT-PRIORITY-QUEUE-SCHEDULER-01', $artifact['task'] ?? null);
        $this->assertSame('external_cron_or_manual_cli', $artifact['trigger'] ?? null);
        $this->assertFalse((bool) data_get($artifact, 'cron_boundary.laravel_scheduler_enabled_by_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_indexing_api_call', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.frontend_code_mutation', true));

        $combined = implode("\n", array_map(
            static fn ($file): string => (string) file_get_contents($file->getPathname()),
            File::allFiles($artifactDir)
        ));
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }
    }

    #[Test]
    public function preflight_only_mode_never_writes_publishes_queues_or_submits(): void
    {
        Http::fake();
        $page = $this->createContentPage();
        $this->seedSeoUrl('https://fermatmind.com/zh/priority-scheduler-page', (string) $page->id);

        $artifactDir = $this->artifactDir();
        $exitCode = Artisan::call('seo-agent:priority-queue-scheduler', [
            '--mode' => 'weekly-l5-low-risk',
            '--sources' => 'cms-tdk-gap',
            '--limit' => 10,
            '--draft-limit' => 10,
            '--publish-limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--preflight-only' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('seo-agent-priority-queue-scheduler.v1', $summary['schema_version'] ?? null);
        $this->assertTrue((bool) ($summary['preflight_only'] ?? false));
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertArrayHasKey('weekly_readonly_runner', $summary['steps'] ?? []);
        $this->assertArrayHasKey('rollback_preflight', $summary['steps'] ?? []);
        $this->assertArrayNotHasKey('weekly_draft_write_auto', $summary['steps'] ?? []);
        $this->assertArrayNotHasKey('cms_publish_auto_canary', $summary['steps'] ?? []);
        $this->assertArrayNotHasKey('post_publish_indexnow_auto', $summary['steps'] ?? []);
        $this->assertSame('pass', data_get($summary, 'url_truth_preflight.status'));
        $this->assertSame('pass', data_get($summary, 'indexnow_config_preflight.status'));
        $this->assertFalse((bool) data_get($summary, 'indexnow_config_preflight.secret_values_printed', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_draft_revision_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.content_page_publish_canary', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_queue_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.indexnow_live_submit', true));

        $fresh = $page->refresh();
        $this->assertSame('not_reviewed', (string) $fresh->claim_gate_status);
        $this->assertSame('draft', (string) $fresh->review_state);
        $this->assertSame(88, (int) $fresh->published_revision_id);
        $this->assertSame(0, CmsTranslationRevision::query()->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('SEO-AGENT-L5A-SCHEDULER-PREFLIGHT-MODE-01', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['preflight_only'] ?? false));
        $this->assertContains('readonly_discovery', $artifact['allowed_actions'] ?? []);
        $this->assertNotContains('cms_draft_revision_write', $artifact['allowed_actions'] ?? []);
        $this->assertNotContains('content_page_publish_canary', $artifact['allowed_actions'] ?? []);
        $this->assertNotContains('indexnow_live_submit', $artifact['allowed_actions'] ?? []);

        $combined = implode("\n", array_map(
            static fn ($file): string => (string) file_get_contents($file->getPathname()),
            File::allFiles($artifactDir)
        ));
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }
    }

    #[Test]
    public function command_fails_closed_for_invalid_limits_and_mode(): void
    {
        $exitCode = Artisan::call('seo-agent:priority-queue-scheduler', [
            '--mode' => 'daily-unbounded',
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('unsupported_mode', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:priority-queue-scheduler', [
            '--publish-limit' => 4,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('publish_limit_out_of_bounds', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:priority-queue-scheduler', [
            '--draft-limit' => 11,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('draft_limit_out_of_bounds', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_priority_queue_scheduler_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-priority-queue-scheduler.v1.json'));

        $this->assertSame('seo-agent-priority-queue-scheduler.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:priority-queue-scheduler', $artifact['command'] ?? null);
        $this->assertSame(10, $artifact['max_draft_rows_per_execution'] ?? null);
        $this->assertSame(3, $artifact['max_content_page_publish_canaries_per_execution'] ?? null);
        $this->assertSame(['indexnow'], $artifact['live_submit_channels_v1'] ?? null);
        $this->assertFalse((bool) ($artifact['laravel_scheduler_enabled_by_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['queue_worker_started_by_pr'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_indexing_api_call', true));
    }

    private function createContentPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'priority-scheduler-page',
            'path' => '/zh/priority-scheduler-page',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Priority Scheduler Page',
            'summary' => 'Existing summary.',
            'seo_title' => '',
            'seo_description' => '',
            'meta_description' => '',
            'canonical_path' => '/zh/priority-scheduler-page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'draft',
            'published_revision_id' => 88,
            'published_at' => now()->subDay(),
        ]);
    }

    private function seedSeoUrl(string $canonicalUrl, string $entityId): void
    {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'locale' => 'zh-CN',
            'page_entity_type' => 'content_page',
            'entity_id_or_slug' => $entityId,
            'cluster' => 'content_page',
            'source_authority' => 'backend_cms',
            'indexability_state' => 'indexable',
            'lastmod_at' => now()->subMinute(),
            'lastmod_source' => 'content_pages.updated_at',
            'is_private_flow' => false,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'metadata_json' => json_encode([
                'claim_safe' => true,
                'claim_boundary_state' => 'approved',
                'publication_state' => 'published',
                'source_table' => 'content_pages',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSeoIntelTables(): void
    {
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
        });

        Schema::connection('seo_intel')->create('seo_search_channel_queue_batches', function ($table): void {
            $table->id();
            $table->string('channel', 64);
            $table->string('status', 64)->default('draft');
            $table->unsignedInteger('item_count')->default(0);
            $table->json('dry_run_report')->nullable();
            $table->text('approval_note')->nullable();
            $table->string('created_by', 128)->nullable();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('source_table', 128)->nullable();
            $table->string('channel', 64);
            $table->string('eligibility_state', 64)->default('eligible');
            $table->string('approval_state', 64)->default('pending');
            $table->string('execution_state', 64)->default('dry_run_ready');
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64)->default('claim_safe');
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('url_hash', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('seo_intel')->create('seo_search_channel_queue_events', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->string('actor_type', 64)->default('system');
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-priority-queue-scheduler-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'raw_url',
            'raw_query',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'secret-indexnow-key',
            'content_md',
            'content_html',
            'cms_draft_body',
            'https://fermatmind.com/zh/priority-scheduler-page',
        ];
    }
}
