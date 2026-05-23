<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix01SingleUrlEnqueueCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.search_channel_queue.write_enabled' => false,
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function command_signature_exposes_canonical_url_and_enqueue(): void
    {
        $command = app(\App\Console\Commands\SeoIntelSearchChannelQueueCommand::class);

        $synopsis = $command->getDefinition()->getSynopsis();

        $this->assertStringContainsString('--canonical-url [CANONICAL-URL]', $synopsis);
        $this->assertStringContainsString('--enqueue', $synopsis);
    }

    #[Test]
    public function dry_run_with_eligible_persisted_url_returns_exactly_one_selected_candidate(): void
    {
        $url = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';
        $this->seedSeoUrl([
            'canonical_url' => $url,
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
            'source_authority' => 'scale_catalog',
            'metadata_json' => ['claim_safe' => true, 'source_table' => 'backend_authority_canary_contract'],
        ]);

        $output = $this->runQueueCommand([
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => $url,
            '--limit' => 20,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertSame($url, $output['canonical_url_filter'] ?? null);
        $this->assertSame(1, $output['candidate_count'] ?? null);
        $this->assertSame(1, $output['eligible_count'] ?? null);
        $this->assertSame(1, $output['planned_queue_count'] ?? null);
        $this->assertSame($url, data_get($output, 'selected_candidate.canonical_url'));
        $this->assertSame('eligible', data_get($output, 'selected_candidate.eligibility_state'));
        $this->assertFalse((bool) ($output['duplicate_detected'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
    }

    #[Test]
    public function dry_run_with_absent_url_returns_zero_candidates_and_safe_issue(): void
    {
        $exitCode = Artisan::call('seo-intel:search-channel-queue', [
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => 'https://fermatmind.com/en/tests/missing-mbti-url',
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertSame(0, $output['candidate_count'] ?? null);
        $this->assertContains('canonical_url_not_found', $output['issues'] ?? []);
    }

    #[Test]
    public function dry_run_with_forbidden_source_authority_does_not_mark_ready(): void
    {
        $url = 'https://fermatmind.com/en/tests/frontend-fallback';
        $this->seedSeoUrl([
            'canonical_url' => $url,
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'frontend-fallback',
            'source_authority' => 'frontend_fallback',
            'metadata_json' => ['claim_safe' => true, 'frontend_fallback' => true],
        ]);

        $exitCode = Artisan::call('seo-intel:search-channel-queue', [
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => $url,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertSame(0, $output['planned_queue_count'] ?? null);
        $this->assertContains('canonical_url_not_eligible', $output['issues'] ?? []);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.source_authority_forbidden'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.frontend_fallback_source'));
    }

    #[Test]
    public function dry_run_with_private_or_noindex_candidate_does_not_mark_ready(): void
    {
        $url = 'https://fermatmind.com/en/tests/private-mbti';
        $this->seedSeoUrl([
            'canonical_url' => $url,
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'private-mbti',
            'source_authority' => 'scale_catalog',
            'indexability_state' => 'noindex',
            'is_private_flow' => true,
            'metadata_json' => ['claim_safe' => true, 'private_flow' => true, 'noindex' => true],
        ]);

        $exitCode = Artisan::call('seo-intel:search-channel-queue', [
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => $url,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('canonical_url_not_eligible', $output['issues'] ?? []);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.private_flow'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.noindex'));
    }

    #[Test]
    public function dry_run_with_canonical_url_uses_persisted_rows_only(): void
    {
        $output = $this->runQueueCommand([
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
        ], expectSuccess: false);

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertSame(0, $output['candidate_count'] ?? null);
        $this->assertContains('canonical_url_not_found', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['url_truth_write_attempted'] ?? true));
        $this->assertFalse((bool) ($output['cms_mutation_attempted'] ?? true));
    }

    #[Test]
    public function enqueue_path_is_blocked_when_write_gate_disabled(): void
    {
        $url = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';
        $this->seedSeoUrl([
            'canonical_url' => $url,
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
            'source_authority' => 'scale_catalog',
            'metadata_json' => ['claim_safe' => true, 'source_table' => 'backend_authority_canary_contract'],
        ]);

        $output = $this->runQueueCommand([
            '--enqueue' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--canonical-url' => $url,
        ], expectSuccess: false);

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['enqueue_attempted'] ?? false));
        $this->assertFalse((bool) ($output['enqueue_committed'] ?? true));
        $this->assertContains('write_gate_disabled', $output['issues'] ?? []);
    }

    #[Test]
    public function duplicate_active_queue_item_is_not_recreated(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);

        $url = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';
        $locale = 'en';
        $channel = 'indexnow';
        $this->seedSeoUrl([
            'canonical_url' => $url,
            'locale' => $locale,
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
            'source_authority' => 'scale_catalog',
            'metadata_json' => ['claim_safe' => true, 'source_table' => 'backend_authority_canary_contract'],
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'batch_id' => null,
            'canonical_url' => $url,
            'locale' => $locale,
            'page_entity_type' => 'test_detail',
            'entity_type' => 'test_detail',
            'entity_id' => 'mbti-personality-test-16-personality-types',
            'source_authority' => 'scale_catalog',
            'source_table' => 'backend_authority_canary_contract',
            'channel' => $channel,
            'eligibility_state' => 'eligible',
            'approval_state' => 'pending',
            'execution_state' => 'dry_run_ready',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_THROW_ON_ERROR),
            'lastmod' => now()->subMinute(),
            'content_hash' => null,
            'url_hash' => hash('sha256', $url),
            'idempotency_key' => hash('sha256', implode('|', [$url, $locale, $channel])),
            'approved_by' => null,
            'approved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $output = $this->runQueueCommand([
            '--enqueue' => true,
            '--json' => true,
            '--channel' => $channel,
            '--canonical-url' => $url,
        ], expectSuccess: false);

        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['duplicate_detected'] ?? false));
        $this->assertFalse((bool) ($output['enqueue_committed'] ?? true));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
    }

    #[Test]
    public function generated_artifact_exists_and_locks_authority_boundaries(): void
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-01-single-url-enqueue-command.v1.json');
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);

        $this->assertSame('seo-growth-mbti-action-01b-fix-01-single-url-enqueue-command.v1', $artifact['schema_version'] ?? null);
        $this->assertTrue((bool) ($artifact['single_url_selector_enabled'] ?? false));
        $this->assertTrue((bool) ($artifact['persisted_url_truth_required'] ?? false));
        $this->assertFalse((bool) ($artifact['sitemap_authority_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['llms_authority_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['frontend_fallback_authority_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['live_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['external_api_call_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['bulk_enqueue_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['duplicate_protection_required'] ?? false));
        $this->assertTrue((bool) ($artifact['write_gate_required'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runQueueCommand(array $arguments, bool $expectSuccess = true): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-queue', $arguments);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($output);
        $this->assertSame($expectSuccess ? 0 : 1, $exitCode, Artisan::output());

        return $output;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedSeoUrl(array $overrides = []): void
    {
        $canonicalUrl = (string) ($overrides['canonical_url'] ?? 'https://www.fermatmind.com/en/research/safe-research-report');
        $metadata = $overrides['metadata_json'] ?? ['claim_safe' => true, 'source_table' => 'research_reports'];

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'locale' => $overrides['locale'] ?? 'en',
            'page_entity_type' => $overrides['page_entity_type'] ?? 'research_report',
            'entity_id_or_slug' => $overrides['entity_id_or_slug'] ?? 'safe-research-report',
            'cluster' => $overrides['cluster'] ?? 'research',
            'source_authority' => $overrides['source_authority'] ?? 'backend_cms',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'lastmod_at' => now()->subHour(),
            'lastmod_source' => $overrides['lastmod_source'] ?? 'research_reports.updated_at',
            'is_private_flow' => (bool) ($overrides['is_private_flow'] ?? false),
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
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
}
