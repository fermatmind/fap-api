<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\PersonalityAgentPostPromotionSearchGateCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonalityAgentPostPromotionSearchGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(PersonalityAgentPostPromotionSearchGateCommand::class)
        );

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.write_enabled' => false,
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function dry_run_outputs_go_for_indexnow_when_surface_url_truth_and_planner_are_ready(): void
    {
        $canonicalUrl = 'https://fermatmind.com/en/personality/enfj-a';
        $this->seedSeoUrl($canonicalUrl);
        $this->fakeHttp([$canonicalUrl]);

        $countsBefore = $this->rowCounts();
        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('GO_FOR_INDEXNOW_DRY_RUN', $output['final_decision'] ?? null);
        $this->assertTrue((bool) ($output['ok'] ?? false));
        $this->assertSame(1, $output['target_count'] ?? null);
        $this->assertSame(1, data_get($output, 'counts.surface_ok'));
        $this->assertSame(1, data_get($output, 'counts.sitemap_llms_ok'));
        $this->assertSame(1, data_get($output, 'counts.url_truth_ready'));
        $this->assertSame(1, data_get($output, 'counts.planned_or_duplicate_safe'));
        $this->assertSame(1, data_get($output, 'targets.0.search_queue_plan.planned_queue_count'));
        $this->assertFalse((bool) data_get($output, 'safety_flags.enqueue_attempted', true));
        $this->assertFalse((bool) data_get($output, 'safety_flags.live_submission_attempted', true));
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function dry_run_does_not_treat_public_results_article_slugs_as_private_surface_leakage(): void
    {
        $canonicalUrl = 'https://fermatmind.com/en/personality/enfj-a';
        $this->seedSeoUrl($canonicalUrl);
        $this->fakeHttp(
            [$canonicalUrl],
            surfaceExtra: "\nhttps://fermatmind.com/en/articles/results-interpretation-guide\nResults summary text for public reading."
        );

        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('GO_FOR_INDEXNOW_DRY_RUN', $output['final_decision'] ?? null);
        $this->assertSame([], data_get($output, 'targets.0.sitemap_llms_membership.issues'));
        $this->assertNotContains('sitemap_private_pattern_present', $output['issues'] ?? []);
        $this->assertNotContains('llms_private_pattern_present', $output['issues'] ?? []);
        $this->assertNotContains('llms_full_private_pattern_present', $output['issues'] ?? []);
    }

    #[Test]
    public function dry_run_blocks_same_origin_private_routes_and_sensitive_query_keys_in_discoverability_surfaces(): void
    {
        $canonicalUrl = 'https://fermatmind.com/en/personality/enfj-a';
        $this->seedSeoUrl($canonicalUrl);
        $this->fakeHttp(
            [$canonicalUrl],
            surfaceExtra: "\nhttps://fermatmind.com/en/results/lookup\nhttps://fermatmind.com/en/personality/enfj-a?token=private"
        );

        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('NO_GO_SURFACE_OR_SAFETY', $output['final_decision'] ?? null);
        $this->assertContains('sitemap_private_pattern_present', $output['issues'] ?? []);
        $this->assertContains('llms_private_pattern_present', $output['issues'] ?? []);
        $this->assertContains('llms_full_private_pattern_present', $output['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function dry_run_reports_url_truth_refresh_required_when_hash_or_lastmod_is_missing(): void
    {
        $canonicalUrl = 'https://fermatmind.com/zh/personality/intp-a';
        $this->seedSeoUrl($canonicalUrl, [
            'locale' => 'zh-CN',
            'lastmod_at' => null,
            'metadata_json' => [
                'claim_safe' => true,
                'claim_boundary_state' => 'approved',
                'publication_state' => 'published',
                'source_table' => 'personality_profile_variants',
            ],
        ]);
        $this->fakeHttp([$canonicalUrl]);

        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('CONDITIONAL_URL_TRUTH_REFRESH_REQUIRED', $output['final_decision'] ?? null);
        $this->assertContains('url_truth_content_hash_missing', $output['issues'] ?? []);
        $this->assertContains('url_truth_lastmod_missing', $output['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function dry_run_reports_duplicate_policy_review_when_active_queue_item_blocks_replan(): void
    {
        $canonicalUrl = 'https://fermatmind.com/zh/personality/esfp-a';
        $this->seedSeoUrl($canonicalUrl, ['locale' => 'zh-CN']);
        $this->seedQueueItem($canonicalUrl, [
            'locale' => 'zh-CN',
            'execution_state' => 'dry_run_ready',
        ]);
        $this->fakeHttp([$canonicalUrl]);

        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('CONDITIONAL_DUPLICATE_OR_POLICY_REVIEW', $output['final_decision'] ?? null);
        $this->assertContains('duplicate_active_queue_item', $output['issues'] ?? []);
        $this->assertTrue((bool) data_get($output, 'targets.0.search_queue_plan.duplicate_detected'));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function dry_run_reports_no_go_for_noindex_or_private_route_surface(): void
    {
        $canonicalUrl = 'https://fermatmind.com/en/personality/enfj-a';
        $this->seedSeoUrl($canonicalUrl);
        $this->fakeHttp([$canonicalUrl], targetHtml: $this->html($canonicalUrl, robots: 'noindex, nofollow', body: '<a href="/results/lookup">lookup</a>'));

        $output = $this->runGate([
            '--dry-run' => true,
            '--json' => true,
            '--urls' => $canonicalUrl,
        ]);

        $this->assertSame('NO_GO_SURFACE_OR_SAFETY', $output['final_decision'] ?? null);
        $this->assertContains('noindex_present', $output['issues'] ?? []);
        $this->assertContains('private_route_or_sensitive_pattern_present', $output['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function command_requires_explicit_dry_run_and_never_calls_external_search_api(): void
    {
        Http::fake();

        $exitCode = Artisan::call('personality:agent-post-promotion-search-gate', [
            '--json' => true,
            '--urls' => 'https://fermatmind.com/en/personality/enfj-a',
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('NO_GO_SAFETY_VIOLATION', $output['final_decision'] ?? null);
        $this->assertContains('dry_run_required', $output['issues'] ?? []);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runGate(array $arguments): array
    {
        $exitCode = Artisan::call('personality:agent-post-promotion-search-gate', $arguments);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($output);

        return $output;
    }

    /**
     * @param  list<string>  $canonicalUrls
     */
    private function fakeHttp(array $canonicalUrls, ?string $targetHtml = null, string $surfaceExtra = ''): void
    {
        $surfaceBody = implode("\n", $canonicalUrls).$surfaceExtra;
        $fakes = [
            'https://fermatmind.com/sitemap.xml' => Http::response($surfaceBody, 200),
            'https://fermatmind.com/llms.txt' => Http::response($surfaceBody, 200),
            'https://fermatmind.com/llms-full.txt' => Http::response($surfaceBody, 200),
        ];

        foreach ($canonicalUrls as $canonicalUrl) {
            $fakes[$canonicalUrl] = Http::response($targetHtml ?? $this->html($canonicalUrl), 200);
        }

        Http::fake($fakes);
    }

    private function html(string $canonicalUrl, string $robots = 'index, follow', string $body = '<main>Promoted public personality content</main>'): string
    {
        return '<!doctype html><html><head><link rel="canonical" href="'.$canonicalUrl.'"><meta name="robots" content="'.$robots.'"></head><body>'.$body.'</body></html>';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedSeoUrl(string $canonicalUrl, array $overrides = []): void
    {
        $metadata = $overrides['metadata_json'] ?? [
            'claim_safe' => true,
            'claim_boundary_state' => 'approved',
            'publication_state' => 'published',
            'source_table' => 'personality_profile_variants',
            'content_hash' => hash('sha256', 'promoted-content-'.$canonicalUrl),
        ];

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'locale' => $overrides['locale'] ?? 'en',
            'page_entity_type' => $overrides['page_entity_type'] ?? 'personality_profile_variant',
            'entity_id_or_slug' => $overrides['entity_id_or_slug'] ?? '301',
            'cluster' => $overrides['cluster'] ?? 'personality',
            'source_authority' => $overrides['source_authority'] ?? 'backend_cms',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'lastmod_at' => array_key_exists('lastmod_at', $overrides) ? $overrides['lastmod_at'] : now()->subMinute(),
            'lastmod_source' => $overrides['lastmod_source'] ?? 'personality_profile_variants.live_content_updated_at',
            'is_private_flow' => (bool) ($overrides['is_private_flow'] ?? false),
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedQueueItem(string $canonicalUrl, array $overrides = []): void
    {
        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'batch_id' => null,
            'canonical_url' => $canonicalUrl,
            'locale' => $overrides['locale'] ?? 'en',
            'page_entity_type' => $overrides['page_entity_type'] ?? 'personality_profile_variant',
            'entity_type' => $overrides['entity_type'] ?? ($overrides['page_entity_type'] ?? 'personality_profile_variant'),
            'entity_id' => $overrides['entity_id'] ?? '301',
            'source_authority' => $overrides['source_authority'] ?? 'backend_cms',
            'source_table' => $overrides['source_table'] ?? 'personality_profile_variants',
            'channel' => $overrides['channel'] ?? 'indexnow',
            'eligibility_state' => 'eligible',
            'approval_state' => $overrides['approval_state'] ?? 'pending',
            'execution_state' => $overrides['execution_state'] ?? 'dry_run_ready',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_THROW_ON_ERROR),
            'lastmod' => $overrides['lastmod'] ?? now()->subHour(),
            'content_hash' => $overrides['content_hash'] ?? null,
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', implode('|', [
                strtolower($canonicalUrl),
                strtolower((string) ($overrides['locale'] ?? 'en')),
                strtolower((string) ($overrides['channel'] ?? 'indexnow')),
            ])),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
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
            $table->string('lastmod_source', 128)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
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
            $table->timestamps();
        });
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'queue_items' => DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count(),
            'seo_urls' => DB::connection('seo_intel')->table('seo_urls')->count(),
        ];
    }
}
