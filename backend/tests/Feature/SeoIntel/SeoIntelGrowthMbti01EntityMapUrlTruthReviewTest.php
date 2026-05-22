<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti01EntityMapUrlTruthReviewTest extends TestCase
{
    #[Test]
    public function entity_map_url_truth_review_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-01-entity-map-url-truth-review.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-01-entity-map-url-truth-review.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-01', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-02', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function url_truth_matrix_classifies_candidates_deferred_surfaces_and_private_flows(): void
    {
        $matrix = $this->artifact()['url_truth_candidate_matrix'] ?? [];

        $this->assertMatrixUrl($matrix, '/en/tests/mbti-personality-test-16-personality-types', 'test_detail', 'candidate_requiring_backend_authoritative_dry_run_confirmation');
        $this->assertMatrixUrl($matrix, '/zh/tests/mbti-personality-test-16-personality-types', 'test_detail', 'candidate_requiring_backend_authoritative_dry_run_confirmation');
        $this->assertMatrixUrl($matrix, '/en/research/mbti-personality-types-salary-turnover-report', 'research_report', 'candidate_requiring_claim_safe_verification');
        $this->assertMatrixUrl($matrix, '/zh/research/mbti-personality-types-salary-turnover-report', 'research_report', 'candidate_requiring_claim_safe_verification');

        $families = array_column($matrix, 'family');
        foreach (['mbti_topic', 'mbti_personality_type_pages', 'mbti_articles', 'private_flows'] as $family) {
            $this->assertContains($family, $families);
        }

        $private = collect($matrix)->firstWhere('family', 'private_flows');
        $this->assertSame('private_noindex_excluded_from_url_truth_and_search_channel', $private['status'] ?? null);
    }

    #[Test]
    public function entity_families_and_entity_key_rules_are_locked(): void
    {
        $artifact = $this->artifact();
        foreach (['mbti_test', 'mbti_topic', 'mbti_research_salary_turnover', 'mbti_result_private', 'mbti_paid_report_private', 'mbti_type_intj', 'mbti_type_infp', 'mbti_type_entj', 'mbti_type_{lowercase_type_code}'] as $entity) {
            $this->assertContains($entity, $artifact['entity_families'] ?? []);
        }

        foreach (['entity_key_independent_from_url_slug', 'translation_group_uuid_preferred_when_available', 'translation_group_id_transitional_only', 'slug_title_similarity_migration_helper_only_not_authority', 'frontend_fallback_cannot_create_entity_key'] as $rule) {
            $this->assertContains($rule, $artifact['entity_key_rules'] ?? []);
        }
    }

    #[Test]
    public function source_authority_rules_allow_backend_sources_and_block_observation_sources(): void
    {
        $artifact = $this->artifact();

        foreach (['scale_catalog', 'backend_public_surface', 'backend_cms', 'research_reports'] as $authority) {
            $this->assertContains($authority, $artifact['allowed_source_authorities'] ?? []);
        }

        foreach (['frontend_fallback', 'static_sitemap_fallback', 'static_llms_fallback', 'crawler_log_source', 'search_engine_response', 'digital_pr_mention', 'local_copy', 'node2_business_db_tencent_rds'] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_sources'] ?? []);
        }
    }

    #[Test]
    public function private_noindex_boundary_and_safety_flags_are_non_mutating(): void
    {
        $artifact = $this->artifact();
        foreach (['take', 'result', 'report', 'paywall', 'order', 'pdf', 'history'] as $path) {
            $this->assertContains($path, $artifact['private_noindex_excluded'] ?? []);
        }

        foreach ($artifact['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_fallback_prohibition_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-01-entity-map-url-truth-review.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-01-entity-map-url-truth-review.v1.json')));

        foreach (['frontend fallback cannot create entity keys', 'static sitemap', 'static llms', 'search responses', 'digital pr mentions', 'excluded from url truth and search channel', 'seo-growth-mbti-02'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @param array<int, array<string, mixed>> $matrix */
    private function assertMatrixUrl(array $matrix, string $url, string $pageEntityType, string $status): void
    {
        $row = collect($matrix)->firstWhere('url', $url);
        $this->assertIsArray($row, 'Missing URL candidate '.$url);
        $this->assertSame($pageEntityType, $row['page_entity_type'] ?? null);
        $this->assertSame($status, $row['status'] ?? null);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-01-entity-map-url-truth-review.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
