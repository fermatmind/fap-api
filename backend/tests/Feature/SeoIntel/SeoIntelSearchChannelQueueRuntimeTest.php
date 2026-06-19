<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelQueueRuntimeTest extends TestCase
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
    public function eligible_research_report_url_can_be_planned_for_queue(): void
    {
        $this->seedSeoUrl();

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true, '--limit' => 20]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertSame(1, $output['candidate_count'] ?? null);
        $this->assertSame(1, $output['eligible_count'] ?? null);
        $this->assertSame(0, $output['blocked_count'] ?? null);
        $this->assertSame(8, $output['planned_queue_count'] ?? null);
        $this->assertSame(1, data_get($output, 'channel_breakdown.indexnow'));
    }

    #[Test]
    public function eligible_backend_cms_article_url_can_be_planned_without_live_submission(): void
    {
        $this->seedSeoUrl([
            'canonical_url' => 'https://www.fermatmind.com/zh/articles/mbti-vs-holland-career-choice',
            'locale' => 'zh-CN',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => '37',
            'cluster' => 'article',
            'source_authority' => 'backend_cms',
            'lastmod_source' => 'articles.updated_at',
            'metadata_json' => [
                'claim_safe' => true,
                'claim_boundary_state' => 'approved',
                'publication_state' => 'published',
                'source_table' => 'articles',
            ],
        ]);

        $output = $this->runQueueCommand([
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--canonical-url' => 'https://www.fermatmind.com/zh/articles/mbti-vs-holland-career-choice',
            '--channel' => 'indexnow',
            '--limit' => 20,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertSame(1, $output['candidate_count'] ?? null);
        $this->assertSame(1, $output['eligible_count'] ?? null);
        $this->assertSame(0, $output['blocked_count'] ?? null);
        $this->assertSame(1, $output['planned_queue_count'] ?? null);
        $this->assertSame(['indexnow' => 1], $output['channel_breakdown'] ?? null);
        $this->assertSame(['article' => 1], $output['page_type_breakdown'] ?? null);
        $this->assertFalse((bool) ($output['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
    }

    #[Test]
    public function eligible_backend_cms_article_url_can_be_written_to_queue_without_live_submission(): void
    {
        config([
            'seo_intel.search_channel_queue.write_enabled' => true,
            'seo_intel.search_channel.live_submission.enabled' => false,
        ]);
        $canonicalUrl = 'https://www.fermatmind.com/zh/articles/mbti-vs-holland-career-choice';
        $this->seedSeoUrl([
            'canonical_url' => $canonicalUrl,
            'locale' => 'zh-CN',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => '37',
            'cluster' => 'article',
            'source_authority' => 'backend_cms',
            'lastmod_source' => 'articles.updated_at',
            'metadata_json' => [
                'claim_safe' => true,
                'claim_boundary_state' => 'approved',
                'publication_state' => 'published',
                'source_table' => 'articles',
            ],
        ]);

        $output = $this->runQueueCommand([
            '--enqueue' => true,
            '--json' => true,
            '--canonical-url' => $canonicalUrl,
            '--channel' => 'indexnow',
            '--limit' => 20,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertTrue((bool) ($output['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertTrue((bool) ($output['enqueue_attempted'] ?? false));
        $this->assertTrue((bool) ($output['enqueue_committed'] ?? false));
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertSame(['indexnow' => 1], $output['channel_breakdown'] ?? null);
        $this->assertSame(['article' => 1], $output['page_type_breakdown'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());

        $item = DB::connection('seo_intel')
            ->table('seo_search_channel_queue_items')
            ->where('canonical_url', $canonicalUrl)
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('article', $item->page_entity_type);
        $this->assertSame('article', $item->entity_type);
        $this->assertSame('37', $item->entity_id);
        $this->assertSame('backend_cms', $item->source_authority);
        $this->assertSame('articles', $item->source_table);
        $this->assertSame('indexnow', $item->channel);
        $this->assertSame('eligible', $item->eligibility_state);
        $this->assertSame('pending', $item->approval_state);
        $this->assertSame('dry_run_ready', $item->execution_state);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_baidu_push_logs')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_indexnow_submissions')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_domestic_submission_logs')->count());
    }

    #[Test]
    public function bounded_enqueue_override_writes_one_article_url_channel_when_write_gate_is_disabled(): void
    {
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->seedSeoUrl([
            'canonical_url' => $canonicalUrl,
            'locale' => 'zh-CN',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => '53',
            'cluster' => 'article',
            'source_authority' => 'backend_cms',
            'lastmod_source' => 'articles.updated_at',
            'metadata_json' => [
                'claim_safe' => true,
                'claim_boundary_state' => 'approved',
                'publication_state' => 'published',
                'source_table' => 'articles',
            ],
        ]);
        $approvalPhrase = sprintf(
            'I explicitly approve SEARCH-CHANNEL-QUEUE-ENQUEUE write for canonical URL %s channel indexnow; no live submission, no CMS content changes, no publish, no schema/hreflang writes, no sitemap/llms mutation.',
            $canonicalUrl,
        );

        $blockedOutput = $this->runQueueCommand([
            '--enqueue' => true,
            '--json' => true,
            '--canonical-url' => $canonicalUrl,
            '--channel' => 'indexnow',
            '--limit' => 20,
        ], expectSuccess: false);

        $this->assertSame('blocked', $blockedOutput['status'] ?? null);
        $this->assertContains('write_gate_disabled', $blockedOutput['issues'] ?? []);
        $this->assertContains('bounded_enqueue_override_confirmation_required', $blockedOutput['issues'] ?? []);
        $this->assertSame($approvalPhrase, $blockedOutput['required_bounded_enqueue_override_confirmation'] ?? null);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());

        $output = $this->runQueueCommand([
            '--enqueue' => true,
            '--json' => true,
            '--canonical-url' => $canonicalUrl,
            '--channel' => 'indexnow',
            '--confirm-bounded-enqueue-override' => $approvalPhrase,
            '--limit' => 20,
        ]);

        $this->assertSame('success', $output['status'] ?? null);
        $this->assertSame('bounded_command_override', $output['write_authorization'] ?? null);
        $this->assertTrue((bool) ($output['config_write_gate_bypassed'] ?? false));
        $this->assertTrue((bool) ($output['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertTrue((bool) ($output['enqueue_committed'] ?? false));
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
        $this->assertSame(['indexnow' => 1], $output['channel_breakdown'] ?? null);
        $this->assertSame(['article' => 1], $output['page_type_breakdown'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_baidu_push_logs')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_indexnow_submissions')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_domestic_submission_logs')->count());
    }

    #[Test]
    public function unsafe_article_urls_remain_blocked(): void
    {
        $unsafeCases = [
            'draft' => [
                'metadata_json' => ['publication_state' => 'draft', 'claim_safe' => true],
                'reason' => 'draft',
            ],
            'noindex' => [
                'indexability_state' => 'noindex',
                'metadata_json' => ['publication_state' => 'published', 'claim_safe' => true],
                'reason' => 'noindex',
            ],
            'private_flow' => [
                'is_private_flow' => true,
                'metadata_json' => ['publication_state' => 'published', 'claim_safe' => true],
                'reason' => 'private_flow',
            ],
            'claim_unsafe' => [
                'metadata_json' => ['publication_state' => 'published', 'claim_safe' => false],
                'reason' => 'claim_unsafe',
            ],
        ];

        foreach ($unsafeCases as $case => $overrides) {
            $this->seedSeoUrl(array_merge([
                'canonical_url' => 'https://www.fermatmind.com/zh/articles/blocked-'.$case,
                'locale' => 'zh-CN',
                'page_entity_type' => 'article',
                'entity_id_or_slug' => 'blocked-'.$case,
                'cluster' => 'article',
                'source_authority' => 'backend_cms',
                'lastmod_source' => 'articles.updated_at',
            ], $overrides));
        }

        $output = $this->runQueueCommand([
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--page-type' => 'article',
            '--limit' => 20,
        ]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(4, $output['blocked_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.draft'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.noindex'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.private_flow'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.claim_unsafe'));
        $this->assertFalse((bool) ($output['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['live_submission_attempted'] ?? true));
    }

    #[Test]
    public function draft_url_is_blocked(): void
    {
        $this->seedSeoUrl(['metadata_json' => ['publication_state' => 'draft']]);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(1, $output['blocked_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.draft'));
    }

    #[Test]
    public function private_url_is_blocked(): void
    {
        $this->seedSeoUrl(['is_private_flow' => true]);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.private_flow'));
    }

    #[Test]
    public function noindex_url_is_blocked(): void
    {
        $this->seedSeoUrl(['indexability_state' => 'noindex']);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.noindex'));
    }

    #[Test]
    public function claim_unsafe_url_is_blocked(): void
    {
        $this->seedSeoUrl(['metadata_json' => ['claim_safe' => false]]);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.claim_unsafe'));
    }

    #[Test]
    public function frontend_and_static_fallback_sources_are_blocked(): void
    {
        $this->seedSeoUrl([
            'canonical_url' => 'https://www.fermatmind.com/en/research/frontend-fallback',
            'source_authority' => 'frontend_fallback',
            'metadata_json' => ['frontend_fallback' => true],
        ]);
        $this->seedSeoUrl([
            'canonical_url' => 'https://www.fermatmind.com/en/research/static-sitemap-fallback',
            'source_authority' => 'static_sitemap_fallback',
            'metadata_json' => ['static_sitemap_fallback' => true],
        ]);
        $this->seedSeoUrl([
            'canonical_url' => 'https://www.fermatmind.com/en/research/static-llms-fallback',
            'source_authority' => 'static_llms_fallback',
            'metadata_json' => ['static_llms_fallback' => true],
        ]);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(3, $output['blocked_count'] ?? null);
        $this->assertSame(3, data_get($output, 'reason_code_breakdown.source_authority_forbidden'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.frontend_fallback_source'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.static_sitemap_fallback_source'));
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.static_llms_fallback_source'));
    }

    #[Test]
    public function node2_local_db_source_is_blocked(): void
    {
        $this->seedSeoUrl([
            'source_authority' => 'node2_local_db',
            'metadata_json' => ['source' => 'node2_local_db'],
        ]);

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(1, data_get($output, 'reason_code_breakdown.node2_local_db_source'));
    }

    #[Test]
    public function private_submission_page_types_are_blocked(): void
    {
        foreach (['take', 'result', 'order', 'checkout', 'pay', 'share', 'report_private', 'private_report'] as $type) {
            $this->seedSeoUrl([
                'canonical_url' => 'https://www.fermatmind.com/en/'.$type.'/blocked',
                'page_entity_type' => $type,
                'entity_id_or_slug' => $type,
            ]);
        }

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true, '--limit' => 20]);

        $this->assertSame(0, $output['eligible_count'] ?? null);
        $this->assertSame(8, $output['blocked_count'] ?? null);
        $this->assertSame(8, data_get($output, 'reason_code_breakdown.page_entity_type_forbidden'));
    }

    #[Test]
    public function dry_run_does_not_write_rows(): void
    {
        $this->seedSeoUrl();

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);

        $this->assertFalse((bool) ($output['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
    }

    #[Test]
    public function no_write_overrides_explicit_enqueue_even_when_write_gate_is_enabled(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->seedSeoUrl();

        $exitCode = Artisan::call('seo-intel:search-channel-queue', [
            '--enqueue' => true,
            '--no-write' => true,
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('enqueue_conflicts_with_dry_run_or_no_write', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
    }

    #[Test]
    public function dry_run_overrides_explicit_enqueue_even_when_write_gate_is_enabled(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->seedSeoUrl();

        $exitCode = Artisan::call('seo-intel:search-channel-queue', [
            '--enqueue' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('enqueue_conflicts_with_dry_run_or_no_write', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
    }

    #[Test]
    public function enqueue_requires_explicit_env_gate(): void
    {
        $this->seedSeoUrl();

        $exitCode = Artisan::call('seo-intel:search-channel-queue', ['--json' => true]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('write_gate_disabled', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
    }

    #[Test]
    public function queue_write_targets_only_search_channel_queue_tables(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->seedSeoUrl();

        $output = $this->runQueueCommand(['--json' => true]);

        $this->assertTrue((bool) ($output['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertSame([
            'seo_search_channel_queue_items',
            'seo_search_channel_queue_batches',
            'seo_search_channel_queue_events',
        ], $output['target_tables'] ?? null);
        $this->assertSame(8, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
        $this->assertSame(8, DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count());
    }

    #[Test]
    public function command_output_shows_no_external_calls_and_no_submission(): void
    {
        $this->seedSeoUrl();

        $output = $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true, '--channel' => 'indexnow']);

        $this->assertFalse((bool) ($output['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($output['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($output['crawler_log_read_attempted'] ?? true));
        $this->assertTrue((bool) data_get($output, 'safety_flags.no_submit_mode'));
        $this->assertSame(['indexnow' => 1], $output['channel_breakdown'] ?? null);
    }

    #[Test]
    public function legacy_submission_tables_are_not_written_by_this_command(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->seedSeoUrl();

        $output = $this->runQueueCommand(['--json' => true]);

        $this->assertTrue((bool) ($output['writes_committed'] ?? false));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_baidu_push_logs')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_indexnow_submissions')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_domestic_submission_logs')->count());
    }

    #[Test]
    public function idempotency_prevents_duplicate_queue_rows_for_same_url_channel(): void
    {
        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->seedSeoUrl();

        $this->runQueueCommand(['--json' => true]);
        $this->runQueueCommand(['--json' => true]);

        $this->assertSame(8, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count());
    }

    #[Test]
    public function audit_event_is_created_only_when_write_gate_is_enabled(): void
    {
        $this->seedSeoUrl();

        $this->runQueueCommand(['--dry-run' => true, '--no-write' => true, '--json' => true]);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());

        config(['seo_intel.search_channel_queue.write_enabled' => true]);
        $this->runQueueCommand(['--json' => true]);

        $this->assertGreaterThan(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
    }

    #[Test]
    public function channel_and_page_type_filters_are_supported(): void
    {
        $this->seedSeoUrl();
        $this->seedSeoUrl([
            'canonical_url' => 'https://www.fermatmind.com/en/tests',
            'page_entity_type' => 'test_hub',
            'entity_id_or_slug' => 'test_hub:en',
            'source_authority' => 'backend_public_surface',
        ]);

        $output = $this->runQueueCommand([
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--channel' => 'indexnow',
            '--page-type' => 'research_report',
            '--limit' => 20,
        ]);

        $this->assertSame(1, $output['candidate_count'] ?? null);
        $this->assertSame(1, $output['planned_queue_count'] ?? null);
        $this->assertSame(['research_report' => 1], $output['page_type_breakdown'] ?? null);
    }

    #[Test]
    public function generated_artifact_and_migration_lock_no_submit_boundary(): void
    {
        $artifactPath = base_path('docs/seo/generated/search-channel-queue-runtime-mvp.v1.json');
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertSame('search-channel-queue-runtime-mvp.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('search_channel_queue', $artifact['runtime'] ?? null);
        $this->assertContains('indexnow', $artifact['allowed_channels'] ?? []);
        $this->assertContains('article', $artifact['allowed_page_entity_types'] ?? []);
        $this->assertContains('research_report', $artifact['allowed_page_entity_types'] ?? []);
        $this->assertContains('take', $artifact['forbidden_page_entity_types'] ?? []);
        $this->assertContains('backend_cms', $artifact['approved_source_authorities'] ?? []);
        $this->assertSame([
            'seo_search_channel_queue_items',
            'seo_search_channel_queue_batches',
            'seo_search_channel_queue_events',
        ], $artifact['target_tables'] ?? null);
        $this->assertTrue((bool) ($artifact['no_live_submission'] ?? false));
        $this->assertTrue((bool) ($artifact['no_external_api'] ?? false));
        $this->assertTrue((bool) ($artifact['dry_run_required_before_write'] ?? false));
        $this->assertSame('SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED', $artifact['write_gate_env'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-QUEUE-01-PROD-MIGRATION-PREFLIGHT', $artifact['next_task'] ?? null);

        $migration = (string) file_get_contents(base_path('database/migrations/seo_intel/2026_05_20_220000_create_seo_search_channel_queue_tables.php'));
        $this->assertStringContainsString('protected $connection = \'seo_intel\';', $migration);
        $this->assertStringNotContainsString('Http::', $migration);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runQueueCommand(array $arguments, bool $expectSuccess = true): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-queue', $arguments);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame($expectSuccess ? 0 : 1, $exitCode, Artisan::output());
        $this->assertIsArray($output);

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

        foreach (['seo_baidu_push_logs', 'seo_indexnow_submissions', 'seo_domestic_submission_logs'] as $table) {
            Schema::connection('seo_intel')->create($table, function ($schema): void {
                $schema->id();
            });
        }
    }
}
