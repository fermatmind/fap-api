<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentPostPublishSearchSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://www.fermatmind.com',
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
    }

    #[Test]
    public function dry_run_plans_post_publish_queue_without_writing(): void
    {
        $page = $this->createPublishedPage();
        $canonicalUrl = 'https://www.fermatmind.com/zh/content-page-candidate';
        $this->seedSeoUrl($canonicalUrl, (string) $page->id);
        $evidencePath = $this->writePublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:post-publish-search-submit', [
            '--publish-evidence' => $evidencePath,
            '--channels' => 'indexnow,google_sitemap',
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(2, $summary['planned_queue_count'] ?? null);
        $this->assertTrue((bool) ($summary['google_indexing_request_planned'] ?? false));
        $this->assertFalse((bool) ($summary['search_channel_enqueue_attempted'] ?? true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_enqueue', true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function execute_enqueues_only_published_url_without_live_submission(): void
    {
        $page = $this->createPublishedPage();
        $canonicalUrl = 'https://www.fermatmind.com/zh/content-page-candidate';
        $this->seedSeoUrl($canonicalUrl, (string) $page->id);
        $evidencePath = $this->writePublishEvidence($page);
        $evidenceSha = hash_file('sha256', $evidencePath) ?: '';

        $exitCode = Artisan::call('seo-agent:post-publish-search-submit', [
            '--publish-evidence' => $evidencePath,
            '--channels' => 'indexnow,google_sitemap',
            '--limit' => 1,
            '--confirm-evidence-sha256' => $evidenceSha,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['search_channel_enqueue_attempted'] ?? false));
        $this->assertTrue((bool) ($summary['search_channel_enqueue_committed'] ?? false));
        $this->assertFalse((bool) ($summary['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($summary['google_indexing_live_api_called'] ?? true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexing_request', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.scheduler_activation', true));
        $this->assertSame(2, $summary['written_items'] ?? null);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());

        $channels = DB::connection('seo_intel')
            ->table('seo_search_channel_queue_items')
            ->orderBy('channel')
            ->pluck('channel')
            ->all();
        $this->assertSame(['google_sitemap', 'indexnow'], $channels);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_baidu_push_logs')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_indexnow_submissions')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_domestic_submission_logs')->count());

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function execute_fails_closed_for_bad_sha_or_unpublished_evidence(): void
    {
        $page = $this->createPublishedPage();
        $this->seedSeoUrl('https://www.fermatmind.com/zh/content-page-candidate', (string) $page->id);
        $evidencePath = $this->writePublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:post-publish-search-submit', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--confirm-evidence-sha256' => str_repeat('0', 64),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('evidence_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());

        $blockedPath = $this->writePublishEvidence($page, ['writes_committed' => false, 'published_count' => 0]);
        $exitCode = Artisan::call('seo-agent:post-publish-search-submit', [
            '--publish-evidence' => $blockedPath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('publish_evidence_missing_one_committed_publish', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_post_publish_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-post-publish-search-submit.v1.json'));

        $this->assertSame('seo-agent-post-publish-search-submit.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:post-publish-search-submit', $artifact['command'] ?? null);
        $this->assertSame(1, $artifact['max_published_urls_per_execution'] ?? null);
        $this->assertContains('content_page', $artifact['supported_targets_v1'] ?? []);
        $this->assertFalse((bool) data_get($artifact, 'live_submission.search_channel_submit', true));
        $this->assertFalse((bool) data_get($artifact, 'live_submission.google_indexing_live_api_call', true));
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

    private function writePublishEvidence(ContentPage $page, array $overrides = []): string
    {
        return $this->writeJson('seo-agent-cms-publish-canary-', array_replace_recursive([
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
        ], $overrides));
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

        foreach (['seo_baidu_push_logs', 'seo_indexnow_submissions', 'seo_domestic_submission_logs'] as $table) {
            Schema::connection('seo_intel')->create($table, function ($schema): void {
                $schema->id();
            });
        }
    }

    private function writeJson(string $prefix, array $payload): string
    {
        $path = storage_path('framework/testing/'.$prefix.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
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
            'content_md',
            'content_html',
            'cms_draft_body',
        ];
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
}
