<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti03bSearchChannelCanaryWavePlanTest extends TestCase
{
    #[Test]
    public function search_channel_canary_plan_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-03b-search-channel-canary-wave-plan.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-03b-search-channel-canary-wave-plan.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-03B', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-04', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function candidate_urls_are_limited_to_test_and_research_after_gates_pass(): void
    {
        $paths = array_column($this->artifact()['candidate_urls_after_gates_pass'] ?? [], 'path');

        foreach ([
            '/en/tests/mbti-personality-test-16-personality-types',
            '/zh/tests/mbti-personality-test-16-personality-types',
            '/en/research/mbti-personality-types-salary-turnover-report',
            '/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $path) {
            $this->assertContains($path, $paths);
        }

        foreach ($this->artifact()['candidate_urls_after_gates_pass'] ?? [] as $candidate) {
            $this->assertContains('url_truth_verified', $candidate['required_gates'] ?? []);
            $this->assertContains('claim_safe', $candidate['required_gates'] ?? []);
        }
    }

    #[Test]
    public function deferred_surfaces_wait_for_backend_authority_and_claim_gates(): void
    {
        foreach (['/en/topics/mbti', '/zh/topics/mbti', 'mbti_personality_type_pages', 'mbti_article_pages'] as $surface) {
            $this->assertContains($surface, $this->artifact()['deferred_until_backend_authority_and_claim_gates_pass'] ?? []);
        }
    }

    #[Test]
    public function required_preconditions_block_unsafe_or_bulk_submission(): void
    {
        foreach (['url_truth_verified', 'allowed_source_authority', 'canonical', 'public', 'indexable', 'claim_safe', 'not_draft', 'not_noindex', 'not_private', 'dry_run_before_enqueue', 'human_approval_before_live_submit', 'one_item_canary', 'no_bulk_submit', 'live_gates_closed_after_any_approved_canary'] as $precondition) {
            $this->assertContains($precondition, $this->artifact()['preconditions'] ?? []);
        }
    }

    #[Test]
    public function approval_template_is_exact_but_inert(): void
    {
        $template = $this->artifact()['future_approval_template'] ?? '';

        $this->assertStringContainsString('exactly one URL', $template);
        $this->assertStringContainsString('{url}', $template);
        $this->assertStringContainsString('No bulk submit', $template);
    }

    #[Test]
    public function authority_boundary_rejects_observation_sources_as_truth(): void
    {
        foreach (['search_response_is_observation_not_url_truth', 'crawler_logs_are_observation_not_url_truth', 'frontend_fallback_static_sitemap_static_llms_digital_pr_mentions_and_local_copies_must_not_create_url_truth'] as $boundary) {
            $this->assertContains($boundary, $this->artifact()['authority_boundary'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prevent_queue_mutation_submission_and_external_calls(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_enqueue_no_submit_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-03b-search-channel-canary-wave-plan.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-03b-search-channel-canary-wave-plan.v1.json')));

        foreach (['does not enqueue', 'does not submit urls', 'does not open live gates', 'does not call external search apis', 'seo-growth-mbti-04'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-03b-search-channel-canary-wave-plan.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
