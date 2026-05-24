<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ResearchReport;
use App\Services\Scale\ScaleRegistry;
use App\Services\SeoIntel\MbtiUrlTruthCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02QueueItem2DryRunSafetyFixTest extends TestCase
{
    use RefreshDatabase;

    private const EN_MBTI_URL = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
        $this->mockScaleRegistry();
        $this->seedResearchReports();
        $this->seedOldWwwRows();
    }

    #[Test]
    public function dry_run_reports_queue_item_2_untouched_for_submitted_en_mbti_item(): void
    {
        $this->seedSubmittedEnMbtiQueueItem();

        $output = $this->runCleanupCommand();

        $this->assertSame('dry_run_ready', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['queue_item_2_untouched'] ?? false));
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['search_channel_enqueue_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['external_api_call_attempted'] ?? true));
        $this->assertFalse((bool) ($output['sitemap_llms_authority_used'] ?? true));
        $this->assertFalse((bool) ($output['frontend_fallback_authority_used'] ?? true));
    }

    #[Test]
    public function dry_run_blocks_when_queue_item_2_is_missing(): void
    {
        $output = $this->runCleanupCommand();

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertFalse((bool) ($output['queue_item_2_untouched'] ?? true));
        $this->assertContains('queue_item_2_missing', $output['issues'] ?? []);
        $this->assertContains('queue_item_2_changed_or_unverified', $output['issues'] ?? []);
    }

    #[Test]
    public function dry_run_blocks_when_queue_item_2_url_mismatches(): void
    {
        $this->seedSubmittedEnMbtiQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/tests/not-mbti',
        ]);

        $output = $this->runCleanupCommand();

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertFalse((bool) ($output['queue_item_2_untouched'] ?? true));
        $this->assertContains('queue_item_2_url_mismatch', $output['issues'] ?? []);
    }

    #[Test]
    public function dry_run_blocks_when_queue_item_2_channel_mismatches(): void
    {
        $this->seedSubmittedEnMbtiQueueItem([
            'channel' => 'bing',
        ]);

        $output = $this->runCleanupCommand();

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertFalse((bool) ($output['queue_item_2_untouched'] ?? true));
        $this->assertContains('queue_item_2_channel_mismatch', $output['issues'] ?? []);
    }

    #[Test]
    public function dry_run_blocks_when_queue_item_2_is_inside_cleanup_target_set(): void
    {
        $this->seedSubmittedEnMbtiQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            'locale' => 'zh-CN',
            'url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'),
        ]);

        $output = $this->runCleanupCommand();

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertFalse((bool) ($output['queue_item_2_untouched'] ?? true));
        $this->assertContains('queue_item_2_url_mismatch', $output['issues'] ?? []);
        $this->assertContains('queue_item_2_in_cleanup_target_set', $output['issues'] ?? []);
    }

    #[Test]
    public function execute_mode_leaves_submitted_queue_item_2_unchanged(): void
    {
        $queueBefore = $this->seedSubmittedEnMbtiQueueItem();

        $output = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['queue_item_2_untouched'] ?? false));
        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertFalse((bool) ($output['search_channel_enqueue_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['external_api_call_attempted'] ?? true));
        $this->assertEquals($queueBefore, (array) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 2)->first());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runCleanupCommand(array $arguments = [
        '--preset' => MbtiUrlTruthCleanupService::PRESET,
        '--dry-run' => true,
        '--no-write' => true,
        '--json' => true,
    ]): array
    {
        $exitCode = Artisan::call('seo-intel:mbti-url-truth-cleanup', $arguments);
        $output = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);
        $this->assertSame(0, $exitCode, (string) json_encode($output, JSON_UNESCAPED_SLASHES));

        return $output;
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
    }

    private function mockScaleRegistry(): void
    {
        $registry = Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->zeroOrMoreTimes()->with(0)->andReturn([
            [
                'code' => 'MBTI',
                'primary_slug' => 'mbti-personality-test-16-personality-types',
                'updated_at' => now()->toISOString(),
                'content_i18n_json' => [
                    'en' => ['catalog' => ['questions_count' => 93, 'time_minutes' => 12]],
                    'zh' => ['catalog' => ['questions_count' => 93, 'time_minutes' => 12]],
                ],
            ],
        ]);

        $this->app->instance(ScaleRegistry::class, $registry);
    }

    private function seedResearchReports(): void
    {
        foreach ([['en', '/en/research/mbti-personality-types-salary-turnover-report'], ['zh-CN', '/zh/research/mbti-personality-types-salary-turnover-report']] as [$locale, $path]) {
            ResearchReport::query()->create([
                'org_id' => 0,
                'slug' => 'mbti-personality-types-salary-turnover-report',
                'locale' => $locale,
                'title' => 'MBTI salary turnover report',
                'executive_summary' => 'Directional research summary.',
                'body_md' => 'Research body.',
                'research_type' => 'salary_turnover',
                'methodology' => 'Modeled index methodology.',
                'sample_disclaimer' => 'Exploratory, non-diagnostic, not hiring advice.',
                'claim_boundary' => 'No salary guarantee or individual prediction.',
                'author_name' => 'FermatMind Research',
                'reviewer_name' => 'FermatMind Review',
                'references' => [['title' => 'Reference', 'url' => 'https://example.com/reference']],
                'downloadable_asset_placeholder' => 'Dataset schema blocked for first publish.',
                'status' => ResearchReport::STATUS_PUBLISHED,
                'review_state' => ResearchReport::REVIEW_APPROVED,
                'is_public' => true,
                'is_indexable' => true,
                'last_reviewed_at' => now()->subDay(),
                'published_at' => now()->subHour(),
                'seo_title' => 'Safe Research Report',
                'seo_description' => 'Safe Research Report description.',
                'canonical_path' => $path,
            ]);
        }
    }

    private function seedOldWwwRows(): void
    {
        foreach ([
            ['https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report', 'en'],
            ['https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report', 'zh-CN'],
        ] as [$url, $locale]) {
            $hash = hash('sha256', $url);
            DB::connection('seo_intel')->table('seo_urls')->insert([
                'canonical_url_hash' => $hash,
                'canonical_url' => $url,
                'locale' => $locale,
                'page_entity_type' => 'research_report',
                'entity_id_or_slug' => 'mbti-personality-types-salary-turnover-report',
                'cluster' => 'research',
                'source_authority' => 'backend_cms',
                'indexability_state' => 'indexable',
                'lastmod_at' => now(),
                'lastmod_source' => 'research_reports.updated_at',
                'is_private_flow' => false,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata_json' => json_encode(['claim_boundary_state' => 'claim_safe'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('seo_intel')->table('seo_url_entities')->insert([
                'canonical_url_hash' => $hash,
                'locale' => $locale,
                'page_entity_type' => 'research_report',
                'entity_id_or_slug' => 'mbti-personality-types-salary-turnover-report',
                'entity_source' => 'research_reports',
                'authority_status' => 'published_approved',
                'source_updated_at' => now(),
                'attributes_json' => json_encode(['source_authority' => 'backend_cms'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function seedSubmittedEnMbtiQueueItem(array $overrides = []): array
    {
        $url = (string) ($overrides['canonical_url'] ?? self::EN_MBTI_URL);
        $row = array_merge([
            'id' => 2,
            'batch_id' => null,
            'canonical_url' => $url,
            'locale' => 'en',
            'page_entity_type' => 'test_detail',
            'entity_type' => 'test_detail',
            'entity_id' => 'mbti-personality-test-16-personality-types',
            'source_authority' => 'scale_catalog',
            'source_table' => 'scales_registry',
            'channel' => 'indexnow',
            'eligibility_state' => 'eligible',
            'approval_state' => 'approved',
            'execution_state' => 'submitted',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_THROW_ON_ERROR),
            'lastmod' => now(),
            'content_hash' => null,
            'url_hash' => hash('sha256', $url),
            'idempotency_key' => hash('sha256', strtolower($url).'|en|indexnow'),
            'approved_by' => 'human',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert($row);

        return (array) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 2)->first();
    }
}
