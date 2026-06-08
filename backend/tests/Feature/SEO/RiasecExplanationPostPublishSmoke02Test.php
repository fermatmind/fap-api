<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationPostPublishSmoke02Test extends TestCase
{
    public function test_post_publish_smoke_02_records_no_go_for_search_submission(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-POST-PUBLISH-SMOKE-02', $artifact['task_id']);
        $this->assertSame('NO_GO_SEARCH_SUBMISSION_SITEMAP_LLMS_NOT_FULLY_CONVERGED', $artifact['decision']);
        $this->assertFalse($artifact['scope']['cms_mutation_performed']);
        $this->assertFalse($artifact['scope']['publish_performed']);
        $this->assertFalse($artifact['scope']['search_submission_performed']);
        $this->assertFalse($artifact['scope']['deploy_performed']);
        $this->assertFalse($artifact['scope']['frontend_deploy_performed']);
        $this->assertFalse($artifact['scope']['article_content_rewrite_performed']);
        $this->assertFalse($artifact['scope']['private_or_tokenized_url_accessed']);
    }

    public function test_public_article_routes_and_api_pass_for_both_locales(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');

        foreach ($artifact['public_route_smoke'] as $smoke) {
            $this->assertSame(200, $smoke['http_status']);
            $this->assertSame('passed', $smoke['status']);
        }
    }

    public function test_schema_and_visible_faq_match_for_both_articles(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');

        foreach ($artifact['schema_smoke'] as $schema) {
            $this->assertSame('passed', $schema['status']);
            $this->assertContains('Article', $schema['json_ld_types']);
            $this->assertContains('BreadcrumbList', $schema['json_ld_types']);
            $this->assertContains('FAQPage', $schema['json_ld_types']);
            $this->assertSame(6, $schema['faq_jsonld_count']);
            $this->assertSame($schema['api_visible_faq_count'], $schema['faq_jsonld_count']);
            $this->assertTrue($schema['faq_schema_matches_visible_faq']);
            $this->assertSame('index, follow', $schema['robots']);
            $this->assertTrue($schema['canonical_link_present']);
        }
    }

    public function test_cta_and_tracking_contract_pass_for_public_riasec_test_routes(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');

        foreach ($artifact['cta_tracking_smoke'] as $cta) {
            $this->assertSame('passed', $cta['status']);
            $this->assertSame(1, $cta['api_cta_bundle_count']);
            $this->assertSame(3, $cta['frontend_cta_count']);
            $this->assertSame(3, $cta['frontend_cta_public_canonical_count']);
            $this->assertTrue($cta['frontend_cta_tracking_params_present']);
            $this->assertSame('holland-career-interest-test-riasec', $cta['api_primary_test_edge']['test_slug']);
            $this->assertSame('public', $cta['api_primary_test_edge']['visibility']);
            $this->assertStringContainsString('article_to_test_click', $cta['tracking_event_code_path']);
        }
    }

    public function test_sitemap_and_llms_are_not_fully_converged(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');
        $surfaces = $artifact['sitemap_llms_smoke'];

        $this->assertFalse($surfaces['fully_converged']);
        $this->assertFalse($surfaces['search_submission_allowed']);
        $this->assertSame('blocked', $surfaces['backend_sitemap_source']['status']);
        $this->assertFalse($surfaces['backend_sitemap_source']['contains_zh_article']);
        $this->assertFalse($surfaces['backend_sitemap_source']['contains_en_article']);
        $this->assertSame('blocked', $surfaces['sitemap_xml']['status']);
        $this->assertFalse($surfaces['sitemap_xml']['contains_zh_article']);
        $this->assertFalse($surfaces['sitemap_xml']['contains_en_article']);
        $this->assertSame('passed', $surfaces['llms_txt']['status']);
        $this->assertTrue($surfaces['llms_txt']['contains_zh_article']);
        $this->assertTrue($surfaces['llms_txt']['contains_en_article']);
        $this->assertSame('blocked', $surfaces['llms_full_txt']['status']);
        $this->assertFalse($surfaces['llms_full_txt']['contains_zh_article']);
        $this->assertTrue($surfaces['llms_full_txt']['contains_en_article']);
    }

    public function test_hard_boundaries_remain_closed_and_artifact_has_no_forbidden_route_patterns(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json');

        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_publish']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_frontend_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
        $this->assertTrue($artifact['hard_boundaries']['cta_public_canonical_only']);

        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-post-publish-smoke-02.v1.json') ?: '';
        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
