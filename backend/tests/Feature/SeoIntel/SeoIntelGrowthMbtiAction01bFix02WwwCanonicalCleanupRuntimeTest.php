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

final class SeoIntelGrowthMbtiAction01bFix02WwwCanonicalCleanupRuntimeTest extends TestCase
{
    use RefreshDatabase;

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
    }

    #[Test]
    public function command_signature_exposes_dry_run_no_write_execute_json_and_preset(): void
    {
        $command = app(\App\Console\Commands\SeoIntelMbtiUrlTruthCleanupCommand::class);
        $synopsis = $command->getDefinition()->getSynopsis();

        $this->assertStringContainsString('--preset [PRESET]', $synopsis);
        $this->assertStringContainsString('--dry-run', $synopsis);
        $this->assertStringContainsString('--no-write', $synopsis);
        $this->assertStringContainsString('--execute', $synopsis);
        $this->assertStringContainsString('--json', $synopsis);
    }

    #[Test]
    public function dry_run_finds_old_www_rows_and_replacement_candidates_without_writing(): void
    {
        $this->seedOldWwwRows();
        $this->seedSubmittedEnMbtiQueueItem();

        $output = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
        ]);

        $this->assertSame('dry_run_ready', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['dry_run'] ?? false));
        $this->assertTrue((bool) ($output['no_write'] ?? false));
        $this->assertFalse((bool) ($output['execute_attempted'] ?? true));
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertSame(2, $output['old_www_rows_found'] ?? null);
        $this->assertTrue((bool) ($output['apex_research_candidates_found'] ?? false));
        $this->assertTrue((bool) ($output['zh_mbti_candidate_found'] ?? false));
        $this->assertSame(0, $output['old_www_rows_retired'] ?? null);
        $this->assertSame(0, $output['apex_research_rows_written'] ?? null);
        $this->assertFalse((bool) ($output['search_channel_enqueue_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['external_api_call_attempted'] ?? true));
        $this->assertFalse((bool) ($output['sitemap_llms_authority_used'] ?? true));
        $this->assertFalse((bool) ($output['frontend_fallback_authority_used'] ?? true));

        $this->assertSame(2, $this->oldWwwActiveCount());
        $this->assertSame(0, $this->apexResearchCount());
        $this->assertSame(0, $this->zhMbtiCount());
    }

    #[Test]
    public function execute_is_blocked_when_no_write_is_present(): void
    {
        $this->seedOldWwwRows();
        $this->seedSubmittedEnMbtiQueueItem();

        $output = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--execute' => true,
            '--no-write' => true,
            '--json' => true,
        ], expectSuccess: false);

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('execute_conflicts_with_dry_run_or_no_write', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertSame(2, $this->oldWwwActiveCount());
        $this->assertSame(0, $this->apexResearchCount());
    }

    #[Test]
    public function execute_retires_www_rows_writes_apex_rows_preserves_queue_item_and_does_not_enqueue_or_submit(): void
    {
        $this->seedOldWwwRows();
        $queueBefore = $this->seedSubmittedEnMbtiQueueItem();

        $output = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['execute_attempted'] ?? false));
        $this->assertFalse((bool) ($output['dry_run'] ?? true));
        $this->assertFalse((bool) ($output['no_write'] ?? true));
        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertSame(2, $output['old_www_rows_retired'] ?? null);
        $this->assertSame(2, $output['apex_research_rows_written'] ?? null);
        $this->assertTrue((bool) ($output['zh_mbti_row_written'] ?? false));
        $this->assertGreaterThanOrEqual(5, (int) ($output['seo_url_entities_updated'] ?? 0));
        $this->assertTrue((bool) ($output['queue_item_2_untouched'] ?? false));
        $this->assertTrue((bool) ($output['duplicate_cluster_prevented'] ?? false));
        $this->assertFalse((bool) ($output['search_channel_enqueue_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['external_api_call_attempted'] ?? true));

        $this->assertSame(0, $this->oldWwwActiveCount());
        $this->assertSame(2, $this->oldWwwSupersededCount());
        $this->assertSame(2, $this->oldWwwEntitySupersededCount());
        $this->assertSame(2, $this->apexResearchCount());
        $this->assertSame(2, $this->apexResearchEntityCount());
        $this->assertSame(1, $this->zhMbtiCount());
        $this->assertSame(1, $this->zhMbtiEntityCount());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertEquals($queueBefore, (array) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 2)->first());
    }

    #[Test]
    public function command_is_idempotent_when_run_twice_and_keeps_duplicate_cluster_prevented(): void
    {
        $this->seedOldWwwRows();
        $this->seedSubmittedEnMbtiQueueItem();

        $first = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--execute' => true,
            '--json' => true,
        ]);
        $second = $this->runCleanupCommand([
            '--preset' => MbtiUrlTruthCleanupService::PRESET,
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame('success', $first['status'] ?? null);
        $this->assertSame('success', $second['status'] ?? null);
        $this->assertSame(2, $first['old_www_rows_retired'] ?? null);
        $this->assertSame(0, $second['old_www_rows_retired'] ?? null);
        $this->assertTrue((bool) ($second['duplicate_cluster_prevented'] ?? false));
        $this->assertSame(5, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(5, DB::connection('seo_intel')->table('seo_url_entities')->count());
        $this->assertSame(0, $this->oldWwwActiveCount());
        $this->assertSame(2, $this->apexResearchCount());
        $this->assertSame(1, $this->zhMbtiCount());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runCleanupCommand(array $arguments, bool $expectSuccess = true): array
    {
        $exitCode = Artisan::call('seo-intel:mbti-url-truth-cleanup', $arguments);
        $output = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);

        if ($expectSuccess) {
            $this->assertSame(0, $exitCode, (string) json_encode($output, JSON_UNESCAPED_SLASHES));
        } else {
            $this->assertNotSame(0, $exitCode, (string) json_encode($output, JSON_UNESCAPED_SLASHES));
        }

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
        $this->createResearchReport('en', '/en/research/mbti-personality-types-salary-turnover-report');
        $this->createResearchReport('zh-CN', '/zh/research/mbti-personality-types-salary-turnover-report');
    }

    private function createResearchReport(string $locale, string $canonicalPath): void
    {
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
            'canonical_path' => $canonicalPath,
        ]);
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

    /** @return array<string, mixed> */
    private function seedSubmittedEnMbtiQueueItem(): array
    {
        $url = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';
        $row = [
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
        ];

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert($row);

        return (array) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 2)->first();
    }

    private function oldWwwActiveCount(): int
    {
        return DB::connection('seo_intel')->table('seo_urls')
            ->where('canonical_url', 'like', 'https://www.fermatmind.com/%/research/mbti-personality-types-salary-turnover-report')
            ->where('indexability_state', 'indexable')
            ->count();
    }

    private function oldWwwSupersededCount(): int
    {
        return DB::connection('seo_intel')->table('seo_urls')
            ->where('canonical_url', 'like', 'https://www.fermatmind.com/%/research/mbti-personality-types-salary-turnover-report')
            ->where('indexability_state', MbtiUrlTruthCleanupService::RETIRED_INDEXABILITY_STATE)
            ->count();
    }

    private function oldWwwEntitySupersededCount(): int
    {
        return DB::connection('seo_intel')->table('seo_url_entities')
            ->where('entity_id_or_slug', 'mbti-personality-types-salary-turnover-report')
            ->where('authority_status', MbtiUrlTruthCleanupService::RETIRED_AUTHORITY_STATUS)
            ->count();
    }

    private function apexResearchCount(): int
    {
        return DB::connection('seo_intel')->table('seo_urls')
            ->whereIn('canonical_url', [
                'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
                'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            ])
            ->where('indexability_state', 'indexable')
            ->count();
    }

    private function apexResearchEntityCount(): int
    {
        return DB::connection('seo_intel')->table('seo_url_entities')
            ->where('entity_id_or_slug', 'mbti-personality-types-salary-turnover-report')
            ->where('authority_status', 'published_approved')
            ->count();
    }

    private function zhMbtiCount(): int
    {
        return DB::connection('seo_intel')->table('seo_urls')
            ->where('canonical_url', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types')
            ->where('indexability_state', 'indexable')
            ->count();
    }

    private function zhMbtiEntityCount(): int
    {
        return DB::connection('seo_intel')->table('seo_url_entities')
            ->where('entity_id_or_slug', 'mbti-personality-test-16-personality-types')
            ->where('authority_status', 'observed')
            ->count();
    }
}
