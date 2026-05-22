<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti02ContentInternalLinkWave1PlanTest extends TestCase
{
    #[Test]
    public function content_internal_link_plan_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-02-content-internal-link-wave1-plan.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-02-content-internal-link-wave1-plan.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-02', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-03A', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function candidate_wave_one_assets_are_scoped_and_deferred_where_needed(): void
    {
        foreach (['mbti_test_page', 'mbti_research_report', 'mbti_topic_hub_deferred_until_backend_topic_authority_explicit', 'two_to_three_mbti_explanatory_articles_only_if_backend_cms_rows_exist', 'selected_personality_type_pages_only_after_entity_authority_confirmed'] as $asset) {
            $this->assertContains($asset, $this->artifact()['candidate_wave1_assets'] ?? []);
        }
    }

    #[Test]
    public function internal_link_families_cover_required_graph_edges(): void
    {
        foreach (['article_to_test', 'article_to_topic', 'article_to_research', 'article_to_related_article', 'topic_to_test', 'topic_to_article', 'topic_to_personality_entity', 'research_to_topic_test_article', 'test_to_article_topic_research', 'personality_page_to_test_topic_article'] as $family) {
            $this->assertContains($family, $this->artifact()['internal_link_families'] ?? []);
        }
    }

    #[Test]
    public function authority_rules_keep_links_backend_owned_and_observation_only(): void
    {
        foreach (['backend_cms_entity_graph_owns_link_truth', 'fap_web_static_links_observation_only', 'sitemap_derived_links_observation_only', 'crawler_logs_cannot_create_links', 'gsc_ga4_referral_suggest_only_cannot_create_links', 'title_slug_similarity_migration_helper_only', 'entity_key_preferred', 'translation_group_uuid_preferred_when_available'] as $rule) {
            $this->assertContains($rule, $this->artifact()['authority_rules'] ?? []);
        }
    }

    #[Test]
    public function dry_run_outputs_are_defined_without_mutation(): void
    {
        foreach (['source_inventory', 'link_family_counts', 'missing_entity_key_count', 'legacy_unpaired_count', 'candidate_opportunity_count', 'unsafe_fallback_source_count', 'warnings'] as $output) {
            $this->assertContains($output, $this->artifact()['dry_run_outputs'] ?? []);
        }
    }

    #[Test]
    public function forbidden_actions_and_safety_flags_block_content_and_link_mutation(): void
    {
        $artifact = $this->artifact();
        foreach (['cms_writes', 'link_creation', 'article_publish', 'fap_web_changes', 'crawler_derived_authority', 'search_response_authority', 'frontend_fallback_authority', 'pseo', 'auto_link_creation'] as $action) {
            $this->assertContains($action, $artifact['forbidden_actions'] ?? []);
        }
        foreach ($artifact['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_content_creation_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-02-content-internal-link-wave1-plan.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-02-content-internal-link-wave1-plan.v1.json')));
        foreach (['does not create content', 'does not mutate cms', 'does not create links', 'fap-web static links are observation only', 'crawler logs cannot create links', 'seo-growth-mbti-03a'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-02-content-internal-link-wave1-plan.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
