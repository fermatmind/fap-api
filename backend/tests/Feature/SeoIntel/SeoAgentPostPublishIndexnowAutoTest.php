<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

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

final class SeoAgentPostPublishIndexnowAutoTest extends TestCase
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
    public function dry_run_plans_indexnow_only_without_queue_write_or_external_call(): void
    {
        Http::fake();
        $page = $this->createPublishedPage();
        $this->seedSeoUrl('https://fermatmind.com/zh/content-page-candidate', (string) $page->id);
        $evidencePath = $this->writeAutoPublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:post-publish-indexnow-auto', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-indexnow-auto'),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, json_encode($summary));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(1, $summary['target_count'] ?? null);
        $this->assertSame(1, $summary['planned_queue_count'] ?? null);
        $this->assertFalse((bool) ($summary['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($summary['google_indexing_live_api_called'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('seo-agent-post-publish-indexnow-auto.v1', $artifact['schema_version'] ?? null);
        $this->assertSame(['indexnow'], data_get($artifact, 'plans.0.selected_channels'));
        $this->assertSame(false, data_get($artifact, 'negative_guarantees.google_indexing_api_call'));
    }

    #[Test]
    public function execute_enqueues_approves_and_submits_indexnow_only(): void
    {
        Http::fake([
            'api.indexnow.test/*' => Http::response('', 202),
        ]);
        $page = $this->createPublishedPage();
        $this->seedSeoUrl('https://fermatmind.com/zh/content-page-candidate', (string) $page->id);
        $evidencePath = $this->writeSinglePublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:post-publish-indexnow-auto', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-indexnow-auto'),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, json_encode($summary));
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(1, $summary['written_items'] ?? null);
        $this->assertSame(1, $summary['approved_count'] ?? null);
        $this->assertSame(1, $summary['submitted_count'] ?? null);
        $this->assertTrue((bool) ($summary['external_calls_attempted'] ?? false));
        $this->assertTrue((bool) ($summary['search_submission_attempted'] ?? false));
        $this->assertFalse((bool) ($summary['google_indexing_live_api_called'] ?? true));

        $item = DB::connection('seo_intel')->table('seo_search_channel_queue_items')->first();
        $this->assertNotNull($item);
        $this->assertSame('indexnow', (string) $item->channel);
        $this->assertSame('approved', (string) $item->approval_state);
        $this->assertSame('submitted', (string) $item->execution_state);

        $events = DB::connection('seo_intel')->table('seo_search_channel_queue_events')->orderBy('id')->pluck('event_type')->all();
        $this->assertContains('queue_item_planned', $events);
        $this->assertContains('search_channel_queue_approved', $events);
        $this->assertContains('bounded_live_submission_started', $events);
        $this->assertContains('bounded_live_submission_response', $events);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && $request['key'] === 'secret-indexnow-key'
                && $request['urlList'] === ['https://fermatmind.com/zh/content-page-candidate'];
        });

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
        $this->assertStringNotContainsString('https://fermatmind.com/zh/content-page-candidate', $encoded);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $artifactEncoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($artifactEncoded);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/content-page-candidate', $artifactEncoded);
        $this->assertStringNotContainsString('secret-indexnow-key', $artifactEncoded);

        $exitCode = Artisan::call('seo-agent:post-publish-indexnow-auto', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-indexnow-auto'),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, json_encode($summary));
        $this->assertSame(0, $summary['submitted_count'] ?? null);
        $this->assertSame(1, $summary['duplicate_submitted_count'] ?? null);
        Http::assertSentCount(1);
    }

    #[Test]
    public function execute_fails_closed_without_url_truth_or_for_limit_above_three(): void
    {
        Http::fake();
        $page = $this->createPublishedPage();
        $evidencePath = $this->writeSinglePublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:post-publish-indexnow-auto', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 4,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('limit_out_of_bounds', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:post-publish-indexnow-auto', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('no_indexnow_queue_items_available', $summary['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function generated_contract_documents_indexnow_auto_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-post-publish-indexnow-auto.v1.json'));

        $this->assertSame('seo-agent-post-publish-indexnow-auto.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:post-publish-indexnow-auto', $artifact['command'] ?? null);
        $this->assertSame(3, $artifact['max_published_urls_per_execution'] ?? null);
        $this->assertSame(['indexnow'], $artifact['live_submit_channels_v1'] ?? null);
        $this->assertContains('google_indexing', $artifact['blocked_live_submit_channels_v1'] ?? []);
        $this->assertSame(false, data_get($artifact, 'negative_guarantees.google_indexing_api_call'));
        $this->assertSame(false, data_get($artifact, 'negative_guarantees.scheduler_activation'));
    }

    private function createPublishedPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'content-page-candidate',
            'path' => '/zh/content-page-candidate',
            'canonical_path' => '/zh/content-page-candidate',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Content Page Candidate',
            'summary' => 'Existing summary.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'published_revision_id' => 88,
            'published_at' => now()->subDay(),
        ]);
    }

    private function writeSinglePublishEvidence(ContentPage $page): string
    {
        return $this->writeJson('seo-agent-cms-publish-canary-', [
            'schema_version' => 'seo-agent-cms-publish-canary.v1',
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'writes_committed' => true,
            'published_count' => 1,
            'affected_refs' => [[
                'status' => 'published',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:'.(int) $page->id.':zh-CN',
                'revision_id' => 101,
                'safe_path' => '/zh/content-page-candidate',
            ]],
            'rollback_evidence' => [
                'available' => true,
                'content_page_ref' => 'content_page:'.(int) $page->id.':zh-CN',
            ],
            'boundaries' => [
                'cms_publish' => true,
                'search_channel_enqueue' => false,
                'indexing_request' => false,
            ],
        ]);
    }

    private function writeAutoPublishEvidence(ContentPage $page): string
    {
        return $this->writeJson('seo-agent-cms-publish-auto-canary-', [
            'schema_version' => 'seo-agent-cms-publish-auto-canary.v1',
            'ok' => true,
            'status' => 'success',
            'publish_summary' => [
                'execute' => true,
                'published_count' => 1,
            ],
            'publish_results' => [[
                'schema_version' => 'seo-agent-cms-publish-canary.v1',
                'ok' => true,
                'status' => 'success',
                'execute' => true,
                'published_count' => 1,
                'affected_refs' => [[
                    'status' => 'published',
                    'target_model' => 'content_page',
                    'subject_ref' => 'content_page:'.(int) $page->id.':zh-CN',
                    'revision_id' => 101,
                    'safe_path' => '/zh/content-page-candidate',
                ]],
            ]],
            'negative_guarantees' => [
                'search_channel_enqueue' => false,
                'indexing_request' => false,
            ],
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

    private function writeJson(string $prefix, array $payload): string
    {
        $path = storage_path('framework/testing/'.$prefix.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
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
        ];
    }
}
