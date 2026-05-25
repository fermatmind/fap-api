<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhArticleCounterpartBatch01Test extends TestCase
{
    private const TARGET_COUNTERPARTS = [
        'big-five-growth-guide',
        'big-five-narrative-portrait',
        'big-five-tool-guide',
        'eq-test-tool-guide',
        'iq-test-growth-guide',
        'iq-test-narrative-portrait',
        'iq-test-tool-guide',
        'mbti-basics',
        'mbti-growth-guide',
        'mbti-narrative-portrait',
    ];

    private const DEFERRED_COUNTERPARTS = [
        'are-infj-men-rare-or-socially-silenced',
        'best-valentines-date-by-personality-and-relationship-science',
        'childhood-dream-job-still-shapes-career-choice',
        'how-16-personality-types-talk-to-an-ai-coach',
        'how-personality-shapes-attitude-toward-ai',
        'which-love-script-fits-you-best',
    ];

    #[Test]
    public function generated_article_batch_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-en-zh-article-counterpart-batch-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-ARTICLE-COUNTERPART-BATCH-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['mass_english_generation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['draft_articles_exposed_in_sitemap_llms'] ?? true));
    }

    #[Test]
    public function article_batch_keeps_counterpart_and_deferred_lists_explicit(): void
    {
        $payload = $this->payload();

        $this->assertSame(20, $payload['article_summary']['repo_baseline_en_articles'] ?? null);
        $this->assertSame(25, $payload['article_summary']['repo_baseline_zh_articles'] ?? null);
        $this->assertSame(10, $payload['article_summary']['en_parity_target_counterparts_ready_for_review'] ?? null);
        $this->assertSame(6, $payload['article_summary']['deferred_missing_english_counterparts'] ?? null);
        $this->assertSame(0, $payload['article_summary']['published_by_this_pr'] ?? null);
        $this->assertSame(0, $payload['article_summary']['sitemap_llms_exposure_added_by_this_pr'] ?? null);

        $ready = collect($payload['import_ready_counterparts'] ?? [])->pluck('source_zh_slug')->all();
        $this->assertSame(self::TARGET_COUNTERPARTS, $ready);
        $this->assertSame(self::DEFERRED_COUNTERPARTS, $payload['deferred_missing_english_counterparts'] ?? null);

        foreach ($payload['import_ready_counterparts'] ?? [] as $item) {
            $this->assertSame('import_ready_review_required', $item['status'] ?? null);
            $this->assertSame(
                'only_after_backend_published_indexable_authority',
                $item['sitemap_llms_exposure_eligible'] ?? null
            );
            $this->assertNotEmpty($item['claim_boundary'] ?? null);
        }
    }

    #[Test]
    public function claim_and_exposure_guards_remain_closed(): void
    {
        $payload = $this->payload();

        $this->assertSame([], $payload['forbidden_claim_hits'] ?? null);
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.draft_articles_must_not_enter_sitemap'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.draft_articles_must_not_enter_llms'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.frontend_fallback_must_not_be_article_authority'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.missing_counterparts_must_remain_explicit'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.published_indexable_authority_required_before_public_exposure'));
        $this->assertNotEmpty($payload['remaining_gaps'] ?? []);
        $this->assertNotEmpty($payload['recommended_next_tasks'] ?? []);
        $this->assertSame('article_counterpart_batch_ready_with_deferred_human_review_assets', $payload['final_decision'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-CAREER-ASSET-BATCH-01', $payload['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-en-zh-article-counterpart-batch-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
