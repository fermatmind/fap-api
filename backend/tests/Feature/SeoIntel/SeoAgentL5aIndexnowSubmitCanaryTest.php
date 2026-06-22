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

final class SeoAgentL5aIndexnowSubmitCanaryTest extends TestCase
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
    public function dry_run_resolves_url_truth_and_plans_indexnow_without_queue_write_or_external_call(): void
    {
        Http::fake();
        $page = $this->createPublishedPage();
        $this->seedSeoUrl('https://fermatmind.com/about', (string) $page->id);
        $evidencePath = $this->writePublishEvidence($page);
        $artifactDir = storage_path('framework/testing/l5a-indexnow-'.Str::uuid()->toString());

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertNull($summary['queue_item_id'] ?? null);
        $this->assertSame(1, $summary['planned_queue_count'] ?? null);
        $this->assertFalse((bool) ($summary['google_indexing_live_api_called'] ?? true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexnow_live_submit', true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('seo-agent-l5a-indexnow-submit-canary.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-AGENT-L5A-INDEXNOW-SUBMIT-CANARY1-01', $artifact['task'] ?? null);
        $this->assertSame('/about', data_get($artifact, 'selected_target.safe_path'));
        $this->assertTrue((bool) data_get($artifact, 'url_truth.ok'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_indexing_api_call', true));
        $this->assertNoForbiddenStrings($artifact);
    }

    #[Test]
    public function execute_requires_publish_sha_then_enqueues_approves_and_submits_indexnow_once(): void
    {
        Http::fake([
            'api.indexnow.test/*' => Http::response('', 202),
        ]);
        $page = $this->createPublishedPage();
        $this->seedSeoUrl('https://fermatmind.com/about', (string) $page->id);
        $evidencePath = $this->writePublishEvidence($page);
        $artifactDir = storage_path('framework/testing/l5a-indexnow-'.Str::uuid()->toString());

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--confirm-publish-evidence-sha256' => str_repeat('0', 64),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('publish_evidence_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--confirm-publish-evidence-sha256' => hash_file('sha256', $evidencePath),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(1, $summary['written_items'] ?? null);
        $this->assertSame(1, $summary['submitted_count'] ?? null);
        $this->assertSame(0, $summary['duplicate_submitted_count'] ?? null);
        $this->assertSame('approved', $summary['approval_state'] ?? null);
        $this->assertSame('submitted', $summary['execution_state'] ?? null);
        $this->assertSame(202, $summary['provider_response_status'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'boundaries.indexnow_live_submit'));
        $this->assertFalse((bool) data_get($summary, 'boundaries.google_indexing_api_call', true));

        $item = DB::connection('seo_intel')->table('seo_search_channel_queue_items')->first();
        $this->assertNotNull($item);
        $this->assertSame('indexnow', (string) $item->channel);
        $this->assertSame('approved', (string) $item->approval_state);
        $this->assertSame('submitted', (string) $item->execution_state);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && $request['key'] === 'secret-indexnow-key'
                && $request['urlList'] === ['https://fermatmind.com/about'];
        });

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame((int) $item->id, $artifact['queue_item_id'] ?? null);
        $this->assertSame(202, $artifact['provider_response_status'] ?? null);
        $this->assertNoForbiddenStrings($artifact);

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--confirm-publish-evidence-sha256' => hash_file('sha256', $evidencePath),
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(0, $summary['submitted_count'] ?? null);
        $this->assertSame(1, $summary['duplicate_submitted_count'] ?? null);
        Http::assertSentCount(1);
    }

    #[Test]
    public function command_fails_closed_without_matching_url_truth_row_or_for_non_one_limit(): void
    {
        Http::fake();
        $page = $this->createPublishedPage();
        $evidencePath = $this->writePublishEvidence($page);

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 2,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('limit_must_be_one', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:l5a-indexnow-submit-canary', [
            '--publish-evidence' => $evidencePath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('url_truth_row_missing', $summary['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        Http::assertNothingSent();
    }

    private function createPublishedPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'canonical_path' => '/about',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'about',
            'title' => 'About FermatMind',
            'summary' => 'Existing summary.',
            'seo_title' => 'About FermatMind | FermatMind',
            'seo_description' => 'Learn about FermatMind and its product boundaries.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
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
            'published_revision_id' => 60,
            'published_at' => now()->subMinute(),
        ]);
    }

    private function writePublishEvidence(ContentPage $page): string
    {
        $path = storage_path('framework/testing/l5a-publish-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => 'seo-agent-l5a-contentpage-publish-canary.v1',
            'task' => 'SEO-AGENT-L5A-CONTENTPAGE-PUBLISH-CANARY1-01',
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'limit' => 1,
            'selected_candidate' => [
                'source_id' => hash('sha256', 'content_page'.$page->id.'canonical'),
                'source_family' => 'cms_tdk_gap',
                'subject_type' => 'content_page',
                'subject_ref' => 'content_page:'.$page->id.':en',
                'target_model' => 'content_page',
                'safe_path' => '/about',
                'severity' => 'p1',
                'gap_codes' => ['missing_canonical'],
                'target_fields' => ['canonical_url_or_path'],
            ],
            'published_count' => 1,
            'rows_skipped_existing' => 0,
            'rollback_evidence' => [
                'available' => true,
                'content_page_ref' => 'content_page:'.$page->id.':en',
            ],
            'published_safe_path' => '/about',
            'url_truth_required' => true,
            'boundaries' => [
                'cms_publish' => true,
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'indexnow_live_submit' => false,
                'google_indexing_api_call' => false,
                'scheduler_activation' => false,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function seedSeoUrl(string $canonicalUrl, string $entityId): void
    {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'locale' => 'en',
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
     * @param  array<string, mixed>  $payload
     */
    private function assertNoForbiddenStrings(array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);

        foreach ([
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
            'https://fermatmind.com/about',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }
}
